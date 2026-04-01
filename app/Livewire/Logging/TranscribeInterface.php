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
use App\Services\DuplicateCheckService;
use App\Services\EventContextService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TranscribeInterface extends Component
{
    use AuthorizesRequests, HasContactForm, HasDuplicateDetection;

    public Station $station;

    public string $workingTime = '';

    public string $contactTime = '';

    public ?int $selectedBandId = null;

    public ?int $selectedModeId = null;

    public ?int $powerWatts = 100;

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

        $event = $this->event;
        if ($event) {
            $this->workingTime = $event->start_time->format('Y-m-d\TH:i');
            $this->contactTime = $this->workingTime;
        }
    }

    public function updatedWorkingTime(): void
    {
        $this->contactTime = $this->workingTime;
    }

    public function updatedExchangeInput(): void
    {
        $this->parseError = '';
        $this->clearDuplicateState();
        $this->parsePreview = [];

        if (trim($this->exchangeInput) === '' || ! $this->selectedBandId || ! $this->selectedModeId) {
            return;
        }

        $tokens = preg_split('/\s+/', trim($this->exchangeInput));
        $firstToken = strtoupper($tokens[0] ?? '');
        if (count($tokens) === 1 && ! str_ends_with($this->exchangeInput, ' ') && strlen($firstToken) >= 2) {
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

        $this->validate([
            'selectedBandId' => 'required|exists:bands,id',
            'selectedModeId' => 'required|exists:modes,id',
            'powerWatts' => 'required|integer|min:1|max:1500',
            'contactTime' => [
                'required',
                function ($attribute, $value, $fail) {
                    $event = $this->event;
                    $time = Carbon::parse($value);
                    $earliest = $event->start_time->copy()->subMinutes(5);
                    $latest = $event->end_time->copy()->addMinutes(5);

                    if ($time->lt($earliest) || $time->gt($latest)) {
                        $fail('Contact time must be within the event window (±5 minutes).');
                    }
                },
            ],
        ]);

        if (trim($this->exchangeInput) === '') {
            $this->parseError = 'Exchange is empty';

            return;
        }

        $parsed = $this->parseExchange();
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

        $contact = Contact::create([
            'event_configuration_id' => $this->station->event_configuration_id,
            'operating_session_id' => $session->id,
            'logger_user_id' => auth()->id(),
            'band_id' => $this->selectedBandId,
            'mode_id' => $this->selectedModeId,
            'qso_time' => Carbon::parse($this->contactTime),
            'callsign' => $parsed['callsign'],
            'section_id' => $parsed['section_id'],
            'received_exchange' => $this->exchangeInput,
            'power_watts' => $this->powerWatts,
            'is_gota_contact' => $this->station->is_gota,
            'points' => ($dupeResult['is_duplicate'] || $this->station->is_gota) ? 0 : $mode->points_fd,
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
        $this->contactTime = $this->workingTime;

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

        return \App\Models\User::query()
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
        $user = \App\Models\User::find($userId);
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
