<?php

namespace App\Livewire\Logging;

use App\Events\ContactLogged;
use App\Livewire\Logging\Concerns\HasContactForm;
use App\Livewire\Logging\Concerns\HasDuplicateDetection;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\DuplicateCheckService;
use App\Services\EventContextService;
use App\Services\ExchangeParserService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TranscribeInterface extends Component
{
    use AuthorizesRequests, HasContactForm, HasDuplicateDetection;

    public Station $station;

    public string $workingDate = '';

    public string $contactTime = '';

    public bool $timeIsLocal = false;

    public ?int $selectedBandId = null;

    public ?int $selectedModeId = null;

    public ?int $powerWatts = null;

    // GOTA operator fields (sticky between contacts)
    public string $gotaOperatorFirstName = '';

    public string $gotaOperatorLastName = '';

    public string $gotaOperatorCallsign = '';

    public ?int $gotaOperatorUserId = null;

    public string $gotaUserSearch = '';

    public function mount(Station $station): void
    {
        $this->authorize('log-contacts');
        $this->station = $station;

        $this->powerWatts = $station->max_power_watts ?? 100;

        $event = $this->event;
        if ($event) {
            $this->workingDate = $event->start_time->format('Y-m-d');
            $this->contactTime = $event->start_time->format('H:i');
        }
    }

    public function updatedExchangeInput(): void
    {
        $this->parseError = '';
        $this->clearDuplicateState();
        $this->parsePreview = [];

        if (trim($this->exchangeInput) === '' || ! $this->selectedBandId || ! $this->selectedModeId) {
            return;
        }

        // Update contactTime display when inline time is present
        $inlineTime = $this->extractInlineTime();
        if ($inlineTime !== null) {
            $this->contactTime = $inlineTime;
        }

        $exchange = $this->getExchangeWithoutTime();
        $tokens = preg_split('/\s+/', trim($exchange));
        $firstToken = strtoupper($tokens[0] ?? '');
        if (count($tokens) === 1 && ! str_ends_with($exchange, ' ') && strlen($firstToken) >= 2) {
            $this->suggestions = $this->findCallsignSuggestions(
                $firstToken,
                $this->selectedBandId,
                $this->selectedModeId,
                $this->station->event_configuration_id,
                $this->station->is_gota,
            );
        }

        $callsign = $this->extractCallsign();
        if ($callsign === null) {
            return;
        }

        $band = Band::find($this->selectedBandId);
        $mode = Mode::find($this->selectedModeId);

        $this->runDuplicateCheck(
            $callsign,
            $this->selectedBandId,
            $this->selectedModeId,
            $this->station->event_configuration_id,
            $band?->name ?? '',
            $mode?->name ?? '',
            $this->station->is_gota,
        );
    }

    public function logContact(): void
    {
        $this->parseError = '';

        if (! $this->event) {
            $this->parseError = 'No active or grace-period event found.';

            return;
        }

        // Extract inline time from exchange (e.g. "1423 W1AW 3A CT")
        $inlineTime = $this->extractInlineTime();
        if ($inlineTime !== null) {
            $this->contactTime = $inlineTime;
        }

        $resolvedTime = $this->resolveContactDateTime();
        if ($resolvedTime === null) {
            $this->parseError = 'Invalid time format. Try 1423, 14:23, or 2:23pm.';

            return;
        }

        $earliest = $this->event->start_time->copy()->subMinutes(5);
        $latest = $this->event->end_time->copy()->addMinutes(5);

        if ($resolvedTime->lt($earliest) || $resolvedTime->gt($latest)) {
            $this->parseError = 'Contact time must be within the event window (±5 minutes).';

            return;
        }

        $this->validate([
            'selectedBandId' => 'required|exists:bands,id',
            'selectedModeId' => 'required|exists:modes,id',
            'powerWatts' => 'required|integer|min:1|max:1500',
        ]);

        $exchange = $this->getExchangeWithoutTime();
        if (trim($exchange) === '') {
            $this->parseError = 'Exchange is empty';

            return;
        }

        $parsed = $this->parseExchangeFromString($exchange);
        if (! $parsed['success']) {
            $this->parseError = implode('. ', $parsed['errors']);

            return;
        }

        $mode = Mode::findOrFail($this->selectedModeId);

        $dupeResult = app(DuplicateCheckService::class)->check(
            $parsed['callsign'],
            $this->selectedBandId,
            $this->selectedModeId,
            $this->station->event_configuration_id,
            $this->station->is_gota,
        );

        $session = $this->getOrCreateTranscriptionSession();

        $contactPoints = $this->station->is_gota ? 5 : $mode->points_fd;

        $contact = Contact::create([
            'event_configuration_id' => $this->station->event_configuration_id,
            'operating_session_id' => $session->id,
            'logger_user_id' => auth()->id(),
            'band_id' => $this->selectedBandId,
            'mode_id' => $this->selectedModeId,
            'qso_time' => $resolvedTime,
            'callsign' => $parsed['callsign'],
            'section_id' => $parsed['section_id'],
            'received_exchange' => $exchange,
            'power_watts' => $this->powerWatts,
            'is_gota_contact' => $this->station->is_gota,
            'points' => $dupeResult['is_duplicate'] ? 0 : $contactPoints,
            'is_duplicate' => $dupeResult['is_duplicate'],
            'duplicate_of_contact_id' => $dupeResult['duplicate_of_contact_id'],
            'is_transcribed' => true,
            'gota_operator_first_name' => $this->station->is_gota ? $this->gotaOperatorFirstName : null,
            'gota_operator_last_name' => $this->station->is_gota ? $this->gotaOperatorLastName : null,
            'gota_operator_callsign' => $this->station->is_gota ? $this->gotaOperatorCallsign : null,
            'gota_operator_user_id' => $this->station->is_gota ? $this->gotaOperatorUserId : null,
        ]);

        ContactLogged::dispatch($contact->load(['band', 'mode', 'section']), $this->event);

        $this->clearInput();
        $this->clearDuplicateState();

        $this->dispatch('contact-logged');
    }

    public function selectSuggestion(string $exchange): void
    {
        $this->exchangeInput = strtoupper(trim($exchange));
        $this->suggestions = [];
        $this->parseError = '';

        $callsign = $this->extractCallsign();
        if ($callsign && $this->selectedBandId && $this->selectedModeId) {
            $band = Band::find($this->selectedBandId);
            $mode = Mode::find($this->selectedModeId);
            $this->runDuplicateCheck(
                $callsign,
                $this->selectedBandId,
                $this->selectedModeId,
                $this->station->event_configuration_id,
                $band?->name ?? '',
                $mode?->name ?? '',
                $this->station->is_gota,
            );
        }

        $this->dispatch('suggestion-selected');
    }

    #[Computed]
    public function isGotaStation(): bool
    {
        return $this->station->is_gota;
    }

    #[Computed]
    public function gotaCallsign(): ?string
    {
        return $this->station->eventConfiguration->gota_callsign;
    }

    #[Computed]
    public function gotaUserResults(): array
    {
        if (strlen($this->gotaUserSearch) < 2) {
            return [];
        }

        return User::query()
            ->where(function ($q) {
                $search = '%'.$this->gotaUserSearch.'%';
                $q->where('call_sign', 'like', $search)
                    ->orWhere('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search);
            })
            ->limit(5)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'label' => trim($u->first_name.' '.$u->last_name).' ('.$u->call_sign.')',
                'first_name' => $u->first_name ?? '',
                'last_name' => $u->last_name ?? '',
                'call_sign' => $u->call_sign ?? '',
            ])
            ->toArray();
    }

    public function selectGotaUser(int $userId): void
    {
        $user = User::find($userId);
        if ($user) {
            $this->gotaOperatorUserId = $user->id;
            $this->gotaOperatorFirstName = $user->first_name ?? '';
            $this->gotaOperatorLastName = $user->last_name ?? '';
            $this->gotaOperatorCallsign = $user->call_sign ?? '';
            $this->gotaUserSearch = '';
        }
    }

    public function clearGotaUser(): void
    {
        $this->gotaOperatorUserId = null;
        $this->gotaOperatorFirstName = '';
        $this->gotaOperatorLastName = '';
        $this->gotaOperatorCallsign = '';
        $this->gotaUserSearch = '';
    }

    #[Computed]
    public function event(): ?Event
    {
        $stationEvent = $this->station->eventConfiguration?->event;

        if (! $stationEvent) {
            return null;
        }

        $service = app(EventContextService::class);
        $status = $service->getGracePeriodStatus($stationEvent);

        if (in_array($status, ['active', 'grace'])) {
            return $stationEvent;
        }

        return null;
    }

    #[Computed]
    public function bands()
    {
        return Band::query()->allowedForFieldDay()->ordered()->get();
    }

    #[Computed]
    public function modes()
    {
        return Mode::all();
    }

    #[Computed]
    public function recentContacts()
    {
        return Contact::query()
            ->where('event_configuration_id', $this->station->event_configuration_id)
            ->where('is_transcribed', true)
            ->whereHas('operatingSession', fn ($q) => $q->where('station_id', $this->station->id))
            ->with('section', 'band', 'mode')
            ->latest('qso_time')
            ->limit(50)
            ->get();
    }

    protected function extractCallsign(): ?string
    {
        return app(ExchangeParserService::class)
            ->extractCallsign($this->getExchangeWithoutTime());
    }

    protected function parseExchangeFromString(string $exchange): array
    {
        return app(ExchangeParserService::class)->parse($exchange);
    }

    /**
     * Extract a time from the first token of the exchange input, if present.
     */
    private function extractInlineTime(): ?string
    {
        $tokens = preg_split('/\s+/', trim($this->exchangeInput));
        if (count($tokens) >= 2) {
            return $this->parseFlexibleTime($tokens[0]);
        }

        return null;
    }

    /**
     * Return the exchange input with any leading time token stripped.
     */
    private function getExchangeWithoutTime(): string
    {
        $input = trim($this->exchangeInput);
        $tokens = preg_split('/\s+/', $input);

        if (count($tokens) >= 2 && $this->parseFlexibleTime($tokens[0]) !== null) {
            return implode(' ', array_slice($tokens, 1));
        }

        return $input;
    }

    #[Computed]
    public function timezoneLabel(): string
    {
        if (! $this->timeIsLocal) {
            return 'UTC';
        }

        return Carbon::now(localTimezone())->format('T');
    }

    /**
     * Parse flexible time input into normalized HH:MM format.
     *
     * Accepts: 1423, 14:23, 2:23pm, 223p, 0023, 123, 2p, 14, 1423z
     */
    private function parseFlexibleTime(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // Strip trailing 'z' or 'Z' (UTC indicator)
        $input = preg_replace('/[zZ]$/', '', $input);

        $hour = null;
        $minute = null;

        if (preg_match('/^(\d{1,2}):(\d{2})\s*([aApP][mM]?)?$/', $input, $m)) {
            // H:MM or HH:MM with optional am/pm
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            if (! empty($m[3])) {
                [$hour, $minute] = $this->applyAmPm($hour, $minute, $m[3]);
            }
        } elseif (preg_match('/^(\d{2})(\d{2})\s*([aApP][mM]?)?$/', $input, $m)) {
            // 4 digits with optional am/pm: 1423, 0223p
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            if (! empty($m[3])) {
                [$hour, $minute] = $this->applyAmPm($hour, $minute, $m[3]);
            }
        } elseif (preg_match('/^(\d)(\d{2})\s*([aApP][mM]?)?$/', $input, $m)) {
            // 3 digits: 123 → 1:23, with optional am/pm
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            if (! empty($m[3])) {
                [$hour, $minute] = $this->applyAmPm($hour, $minute, $m[3]);
            }
        } elseif (preg_match('/^(\d{1,2})\s*([aApP][mM]?)$/', $input, $m)) {
            // Hour-only with am/pm: 2p, 2pm, 12a
            $hour = (int) $m[1];
            $minute = 0;
            [$hour, $minute] = $this->applyAmPm($hour, $minute, $m[2]);
        } elseif (preg_match('/^(\d{1,2})$/', $input, $m)) {
            // Hour-only: 14, 2
            $hour = (int) $m[1];
            $minute = 0;
        }

        if ($hour === null || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @return array{int, int}
     */
    private function applyAmPm(int $hour, int $minute, string $suffix): array
    {
        $isPm = stripos($suffix, 'p') === 0;
        if ($isPm && $hour < 12) {
            $hour += 12;
        }
        if (! $isPm && $hour === 12) {
            $hour = 0;
        }

        return [$hour, $minute];
    }

    private function resolveContactDateTime(): ?Carbon
    {
        $time = $this->parseFlexibleTime($this->contactTime);
        if ($time === null) {
            return null;
        }

        $dateTimeStr = $this->workingDate.' '.$time;

        if ($this->timeIsLocal) {
            return Carbon::parse($dateTimeStr, localTimezone())->utc();
        }

        return Carbon::parse($dateTimeStr, 'UTC');
    }

    private function getOrCreateTranscriptionSession(): OperatingSession
    {
        return OperatingSession::firstOrCreate(
            [
                'station_id' => $this->station->id,
                'is_transcription' => true,
            ],
            [
                'operator_user_id' => auth()->id(),
                'start_time' => $this->event->start_time,
                'end_time' => $this->event->end_time,
                'is_transcription' => true,
                'power_watts' => $this->station->max_power_watts ?? 100,
                'qso_count' => 0,
            ]
        );
    }

    public function render(): View
    {
        return view('livewire.logging.transcribe-interface')
            ->layout('layouts.app');
    }
}
