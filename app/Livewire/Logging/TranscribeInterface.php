<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Logging;

use App\Events\ContactLogged;
use App\Livewire\Logging\Concerns\HasContactForm;
use App\Livewire\Logging\Concerns\HasDuplicateDetection;
use App\Livewire\Logging\Concerns\HasParticipantWarning;
use App\Livewire\Logging\Concerns\HasRecentContactNumbers;
use App\Models\AuditLog;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TranscribeInterface extends Component
{
    use AuthorizesRequests, HasContactForm, HasDuplicateDetection, HasParticipantWarning, HasRecentContactNumbers;

    /**
     * Splits an exchange string on any run of whitespace.
     */
    private const WHITESPACE_PATTERN = '/\s+/';

    /**
     * Paper logs are often kept against a clock that is a few minutes off, so
     * entry is allowed this far outside the event. Such contacts earn no points.
     */
    private const CLOCK_DRIFT_MINUTES = 5;

    private const OUT_OF_WINDOW_ERROR = 'Contact time must be within the event window (±5 minutes).';

    private const FUTURE_TIME_ERROR = 'Contact time is in the future. Check the time and the UTC/local setting.';

    private const INVALID_TIME_ERROR = 'Invalid time format. Try 1423, 14:23, or 2:23pm.';

    public Station $station;

    public string $workingDate = '';

    public string $contactTime = '';

    public bool $timeIsLocal = false;

    public ?int $selectedBandId = null;

    public ?int $selectedModeId = null;

    public ?int $powerWatts = null;

    public string $submode = '';

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

    public function updatedTimeIsLocal(bool $value): void
    {
        if ($this->workingDate === '' || $this->contactTime === '') {
            return;
        }

        $time = $this->parseFlexibleTime($this->contactTime);
        if ($time === null) {
            return;
        }

        $dateTimeStr = $this->workingDate.' '.$time;

        $carbon = $value
            ? Carbon::parse($dateTimeStr, 'UTC')->setTimezone(localTimezone())
            : Carbon::parse($dateTimeStr, localTimezone())->utc();

        $this->workingDate = $carbon->format('Y-m-d');
        $this->contactTime = $carbon->format('H:i');
    }

    public function updatedExchangeInput(): void
    {
        $this->parseError = '';
        $this->clearDuplicateState();
        $this->parsePreview = [];
        $this->participantWarning = '';

        if (trim($this->exchangeInput) !== '') {
            $this->refreshParticipantWarning($this->station->event_configuration_id);
        }

        if (trim($this->exchangeInput) === '' || ! $this->selectedBandId || ! $this->selectedModeId) {
            return;
        }

        // Update contactTime display when inline time is present
        $inlineTime = $this->extractInlineTime();
        if ($inlineTime !== null) {
            $this->contactTime = $inlineTime;
        }

        $exchange = $this->getExchangeWithoutTime();
        $tokens = preg_split(self::WHITESPACE_PATTERN, trim($exchange));
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
            $this->parseError = 'Transcription is unavailable outside the event and its grace period.';

            return;
        }

        if ($this->hasUnreadableLeadingTime($this->exchangeInput)) {
            $this->parseError = self::INVALID_TIME_ERROR;

            return;
        }

        // Extract inline time from exchange (e.g. "1423 W1AW 3A CT")
        $inlineTime = $this->extractInlineTime();
        if ($inlineTime !== null) {
            $this->contactTime = $inlineTime;
        }

        $resolvedTime = $this->resolveContactDateTime();
        if ($resolvedTime === null) {
            $this->parseError = self::INVALID_TIME_ERROR;

            return;
        }

        $timeError = $this->contactTimeError($resolvedTime);
        if ($timeError !== null) {
            $this->parseError = $timeError;

            return;
        }

        $this->validate([
            'selectedBandId' => 'required|exists:bands,id',
            'selectedModeId' => 'required|exists:modes,id',
            'powerWatts' => 'required|integer|min:1|max:1500',
            'submode' => 'nullable|string|max:20',
        ]);

        if ($this->station->is_gota && ! $this->hasGotaOperator()) {
            $this->parseError = 'Enter the GOTA operator in the GOTA Operator panel before logging.';

            return;
        }

        $exchange = $this->getExchangeWithoutTime();
        if (trim($exchange) === '') {
            $this->parseError = 'Enter callsign, class, and section';

            return;
        }

        $parsed = $this->parseExchangeFromString($exchange);
        if (! $parsed['success']) {
            $this->parseError = implode('. ', $parsed['errors']);

            return;
        }

        $session = $this->getOrCreateTranscriptionSession();

        $contact = new Contact([
            'event_configuration_id' => $this->station->event_configuration_id,
            'operating_session_id' => $session->id,
            'logger_user_id' => auth()->id(),
            'band_id' => $this->selectedBandId,
            'mode_id' => $this->selectedModeId,
            'submode' => $this->submode,
            'qso_time' => $resolvedTime,
            'callsign' => $parsed['callsign'],
            'section_id' => $parsed['section_id'],
            'exchange_class' => $parsed['transmitter_count'].$parsed['class_code'],
            'power_watts' => $this->powerWatts,
            'is_gota_contact' => $this->station->is_gota,
            'is_transcribed' => true,
            'gota_operator_first_name' => $this->station->is_gota ? $this->gotaOperatorFirstName : null,
            'gota_operator_last_name' => $this->station->is_gota ? $this->gotaOperatorLastName : null,
            'gota_operator_callsign' => $this->station->is_gota ? $this->gotaOperatorCallsign : null,
            'gota_operator_user_id' => $this->station->is_gota ? $this->gotaOperatorUserId : null,
        ]);

        // A time inside the ±5 minute clock-drift allowance but outside the
        // event itself is stored as entered but earns no points (see
        // DuplicateCheckService::settleGroup) and is left out of Cabrillo.
        $dupes = app(DuplicateCheckService::class);
        $dupes->locked($contact->event_configuration_id, function () use ($dupes, $contact, $session) {
            $dupes->save($contact);
            $session->increment('qso_count');
        });

        ContactLogged::dispatch($contact->load(['band', 'mode', 'section']), $this->event);

        $this->clearInput();
        $this->clearDuplicateState();
        $this->participantWarning = '';

        $this->dispatch('contact-logged');
    }

    public function selectSuggestion(string $exchange): void
    {
        $this->exchangeInput = strtoupper(trim($exchange));
        $this->suggestions = [];
        $this->parseError = '';
        $this->refreshParticipantWarning($this->station->event_configuration_id);

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

    /**
     * Whether a GOTA operator is named: a linked user, or a first and last name.
     */
    private function hasGotaOperator(): bool
    {
        return $this->gotaOperatorUserId !== null
            || (trim($this->gotaOperatorFirstName) !== '' && trim($this->gotaOperatorLastName) !== '');
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

    public function deleteContact(int $contactId): void
    {
        if (! $this->event) {
            abort(403);
        }

        $contact = $this->findTranscribedContact($contactId);

        if ($contact === null) {
            abort(403);
        }

        $this->authorizeContactChange($contact);

        AuditLog::log(
            'contact.deleted',
            auditable: $contact,
            oldValues: [
                'callsign' => $contact->callsign,
                'exchange_class' => $contact->exchange_class,
                'session_id' => $contact->operating_session_id,
            ],
        );

        $dupes = app(DuplicateCheckService::class);
        $dupes->locked($contact->event_configuration_id, function () use ($dupes, $contact) {
            $contact->delete();
            $dupes->reevaluate($contact);
            $contact->operatingSession?->decrement('qso_count');
        });

        unset($this->recentContacts);
    }

    public function restoreContact(int $contactId): void
    {
        if (! $this->event) {
            abort(403);
        }

        $contact = Contact::onlyTrashed()
            ->where('id', $contactId)
            ->where('event_configuration_id', $this->station->event_configuration_id)
            ->where('is_transcribed', true)
            ->whereHas('operatingSession', fn ($q) => $q->where('station_id', $this->station->id))
            ->first();

        if ($contact === null) {
            abort(403);
        }

        $this->authorizeContactChange($contact);

        $dupes = app(DuplicateCheckService::class);
        $dupes->locked($contact->event_configuration_id, function () use ($dupes, $contact) {
            $contact->restore();
            $dupes->reevaluate($contact);
            $contact->operatingSession?->increment('qso_count');
        });

        AuditLog::log(
            'contact.restored',
            auditable: $contact,
            newValues: [
                'callsign' => $contact->callsign,
                'exchange_class' => $contact->exchange_class,
                'session_id' => $contact->operating_session_id,
            ],
        );

        unset($this->recentContacts);
    }

    public function updateContact(int $contactId, string $exchangeInput): void
    {
        if (! $this->event) {
            abort(403);
        }

        $contact = $this->findTranscribedContact($contactId);

        if ($contact === null) {
            abort(403);
        }

        $this->authorizeContactChange($contact);

        if ($this->hasUnreadableLeadingTime($exchangeInput)) {
            $this->parseError = self::INVALID_TIME_ERROR;

            return;
        }

        // Check for inline time prefix (e.g. "1423 W1AW 3A CT")
        $inlineTime = null;
        $exchange = trim($exchangeInput);
        $tokens = preg_split(self::WHITESPACE_PATTERN, $exchange);

        if (count($tokens) >= 2) {
            $possibleTime = $this->parseFlexibleTime($tokens[0]);
            if ($possibleTime !== null) {
                $inlineTime = $possibleTime;
                $exchange = implode(' ', array_slice($tokens, 1));
            }
        }

        $parsed = app(ExchangeParserService::class)->parse($exchange, $this->eventTypeId());

        if (! $parsed['success']) {
            $this->parseError = $parsed['errors'][0] ?? 'Invalid exchange';

            return;
        }

        $oldValues = [
            'callsign' => $contact->callsign,
            'exchange_class' => $contact->exchange_class,
            'section_id' => $contact->section_id,
            'qso_time' => $contact->qso_time->toIso8601String(),
        ];

        $updateData = [
            'callsign' => $parsed['callsign'],
            'exchange_class' => $parsed['transmitter_count'].$parsed['class_code'],
            'section_id' => $parsed['section_id'],
        ];

        // If inline time provided, update qso_time using original date + new time
        if ($inlineTime !== null) {
            $tz = $this->timeIsLocal ? localTimezone() : 'UTC';
            $baseDate = $contact->qso_time->copy()->setTimezone($tz)->format('Y-m-d');
            $updateData['qso_time'] = Carbon::parse($baseDate.' '.$inlineTime, $tz)->utc();

            $timeError = $this->contactTimeError($updateData['qso_time']);
            if ($timeError !== null) {
                $this->parseError = $timeError;

                return;
            }
        }

        $dupes = app(DuplicateCheckService::class);
        $previousKey = $dupes->keyFor($contact);

        $contact->fill($updateData);
        $dupes->save($contact, $previousKey);

        AuditLog::log(
            'contact.updated',
            auditable: $contact,
            oldValues: $oldValues,
            newValues: [
                'callsign' => $contact->callsign,
                'exchange_class' => $contact->exchange_class,
                'section_id' => $contact->section_id,
                'qso_time' => $contact->qso_time->toIso8601String(),
            ],
        );

        $this->exchangeInput = '';
        $this->parseError = '';
        $this->clearDuplicateState();
        $this->participantWarning = '';
        unset($this->recentContacts);
    }

    /**
     * Why a contact time can't be used, or null when it can: it must be
     * inside the event and not ahead of the clock, give or take the
     * clock-drift allowance.
     */
    private function contactTimeError(Carbon $time): ?string
    {
        $earliest = $this->event->start_time->copy()->subMinutes(self::CLOCK_DRIFT_MINUTES);
        $latest = $this->event->end_time->copy()->addMinutes(self::CLOCK_DRIFT_MINUTES);

        if ($time->lt($earliest) || $time->gt($latest)) {
            return self::OUT_OF_WINDOW_ERROR;
        }

        if ($time->gt(appNow()->addMinutes(self::CLOCK_DRIFT_MINUTES))) {
            return self::FUTURE_TIME_ERROR;
        }

        return null;
    }

    /**
     * Whether the exchange starts with something meant as a time that
     * doesn't read as one, like "2575". Callsigns always have a letter, so
     * a leading all-digit token is a time attempt.
     */
    private function hasUnreadableLeadingTime(string $input): bool
    {
        $tokens = preg_split(self::WHITESPACE_PATTERN, trim($input));

        return count($tokens) >= 2
            && preg_match('/^\d{1,2}:?\d{0,2}\s*([aApP][mM]?|[zZ])?$/', $tokens[0]) === 1
            && $this->parseFlexibleTime($tokens[0]) === null;
    }

    /**
     * Editing or removing a transcribed contact needs the edit-contacts
     * permission, unless the user transcribed it themselves (mirroring the
     * live logger, where operators manage only their own session's contacts).
     */
    private function authorizeContactChange(Contact $contact): void
    {
        if ($contact->logger_user_id === auth()->id()) {
            return;
        }

        $this->authorize('update', $contact);
    }

    #[Computed]
    public function recentContacts()
    {
        return $this->transcribedContactsQuery()
            ->withTrashed()
            ->with('section', 'band', 'mode')
            ->latest('qso_time')
            ->latest('id')
            ->limit(50)
            ->get();
    }

    /**
     * Contacts transcribed for this station.
     *
     * @return Builder<Contact>
     */
    protected function transcribedContactsQuery(): Builder
    {
        return Contact::query()
            ->where('event_configuration_id', $this->station->event_configuration_id)
            ->where('is_transcribed', true)
            ->whereHas('operatingSession', fn ($q) => $q->where('station_id', $this->station->id));
    }

    protected function activeContactCount(): int
    {
        return $this->transcribedContactsQuery()->count();
    }

    protected function findTranscribedContact(int $contactId): ?Contact
    {
        return $this->transcribedContactsQuery()
            ->where('id', $contactId)
            ->first();
    }

    /**
     * Event type of the event being transcribed, so the parser only accepts
     * the class letters this rulebook defines.
     */
    protected function eventTypeId(): ?int
    {
        return $this->station->eventConfiguration?->event?->event_type_id;
    }

    /**
     * Sample callsign, class and section for the event's rulebook, shown as
     * the exchange placeholder and help text.
     */
    #[Computed]
    public function exchangeExample(): string
    {
        $code = $this->station->eventConfiguration?->event?->eventType?->code;

        return $code === 'WFD' ? 'W1AW 1H CT' : 'W1AW 3A CT';
    }

    protected function extractCallsign(): ?string
    {
        return app(ExchangeParserService::class)
            ->extractCallsign($this->getExchangeWithoutTime());
    }

    protected function parseExchangeFromString(string $exchange): array
    {
        return app(ExchangeParserService::class)->parse($exchange, $this->eventTypeId());
    }

    /**
     * Extract a time from the first token of the exchange input, if present.
     */
    private function extractInlineTime(): ?string
    {
        $tokens = preg_split(self::WHITESPACE_PATTERN, trim($this->exchangeInput));
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
        $tokens = preg_split(self::WHITESPACE_PATTERN, $input);

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

        // Hour+minute forms share one body: H:MM / HH:MM (colon-separated),
        // HHMM (4 digits), and HMM (3 digits), each with optional am/pm.
        $hourMinutePattern = '/^(?:(\d{1,2}):(\d{2})|(\d{2})(\d{2})|(\d)(\d{2}))\s*([aApP][mM]?)?$/';

        if (preg_match($hourMinutePattern, $input, $m)) {
            // Exactly one alternative matches; its pair is the first of
            // groups 1/2, 3/4, 5/6 that participated in the match.
            foreach ([[1, 2], [3, 4], [5, 6]] as [$hourGroup, $minuteGroup]) {
                if (($m[$hourGroup] ?? '') !== '') {
                    $hour = (int) $m[$hourGroup];
                    $minute = (int) $m[$minuteGroup];
                    break;
                }
            }
            if (! empty($m[7])) {
                [$hour, $minute] = $this->applyAmPm($hour, $minute, $m[7]);
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
            ->layout('layouts.app', ['title' => 'Transcribe Paper Log']);
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'selectedBandId' => 'band',
            'selectedModeId' => 'mode',
            'powerWatts' => 'power',
        ];
    }
}
