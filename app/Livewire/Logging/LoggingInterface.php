<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Logging;

use App\Events\ContactChanged;
use App\Exceptions\BandModeSwitchException;
use App\Livewire\Logging\Concerns\HasContactForm;
use App\Livewire\Logging\Concerns\HasDuplicateDetection;
use App\Livewire\Logging\Concerns\HasParticipantWarning;
use App\Livewire\Logging\Concerns\HasRecentContactNumbers;
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\User;
use App\Services\BandModeSwitchService;
use App\Services\BandResolverService;
use App\Services\DuplicateCheckService;
use App\Services\EmptySessionPruner;
use App\Services\ExchangeParserService;
use App\Services\LoggingWarningService;
use App\Support\AdvancedContactFields;
use App\Support\BroadcastChannels;
use App\Support\StationMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class LoggingInterface extends Component
{
    use HasContactForm, HasDuplicateDetection, HasParticipantWarning, HasRecentContactNumbers;

    public OperatingSession $operatingSession;

    public string $gotaOperatorFirstName = '';

    public string $gotaOperatorLastName = '';

    public string $gotaOperatorCallsign = '';

    public ?int $gotaOperatorUserId = null;

    public string $gotaUserSearch = '';

    public string $bandModeWarning = '';

    public function mount(OperatingSession $operatingSession): void
    {
        if ($operatingSession->operator_user_id !== auth()->id()) {
            abort(403);
        }

        if (! StationMode::allowsStation($operatingSession->station_id)) {
            abort(403, 'This browser can only log for its assigned station.');
        }

        if ($operatingSession->end_time !== null) {
            $this->redirect(route('logging.station-select'), navigate: true);

            return;
        }

        if ($operatingSession->external_source !== null) {
            session()->flash('warning', "This session is managed by {$operatingSession->external_source}.");
            $this->redirect(route('logging.station-select'), navigate: true);

            return;
        }

        $event = $operatingSession->station->eventConfiguration?->event;
        if (! $event || Carbon::parse($event->operatingEndsAt())->lt(appNow())) {
            $operatingSession->update(['end_time' => $event?->operatingEndsAt() ?? appNow()]);
            $this->redirect(route('logging.station-select'), navigate: true);

            return;
        }

        $this->operatingSession = $operatingSession;
        $this->refreshBandModeWarning();
        $this->restoreGotaOperator();
    }

    /**
     * Refill the GOTA operator panel from the latest contact in this session
     * or the ones it continues through band/mode switches, so a page reload
     * or a switch keeps the operator who's at the radio. A fresh session
     * starts empty.
     */
    protected function restoreGotaOperator(): void
    {
        if (! $this->operatingSession->station->is_gota) {
            return;
        }

        $lastContact = Contact::query()
            ->whereIn('operating_session_id', [$this->operatingSession->id, ...$this->operatingSession->switchChainIds()])
            ->where(fn ($query) => $query->whereNotNull('gota_operator_user_id')
                ->orWhereNotNull('gota_operator_first_name')
                ->orWhereNotNull('gota_operator_callsign'))
            ->latest('qso_time')
            ->latest('id')
            ->first();

        if (! $lastContact) {
            return;
        }

        $this->gotaOperatorUserId = $lastContact->gota_operator_user_id;
        $this->gotaOperatorFirstName = $lastContact->gota_operator_first_name ?? '';
        $this->gotaOperatorLastName = $lastContact->gota_operator_last_name ?? '';
        $this->gotaOperatorCallsign = $lastContact->gota_operator_callsign ?? '';
    }

    public function refreshBandModeWarning(): void
    {
        $this->bandModeWarning = app(LoggingWarningService::class)->bandModeConflictWarning(
            $this->operatingSession->station,
            $this->operatingSession->band_id,
            $this->operatingSession->mode_id,
        ) ?? '';
    }

    public function updatedExchangeInput(): void
    {
        $this->parseError = '';
        $this->clearDuplicateState();
        $this->parsePreview = [];
        $this->participantWarning = '';

        if (trim($this->exchangeInput) === '') {
            return;
        }

        $this->refreshParticipantWarning($this->operatingSession->station->event_configuration_id);

        // Cannot perform duplicate detection or suggestions without knowing current band/mode
        if ($this->operatingSession->band_id === null || $this->operatingSession->mode_id === null) {
            return;
        }

        $tokens = preg_split('/\s+/', trim($this->exchangeInput));
        $firstToken = strtoupper($tokens[0] ?? '');
        if (count($tokens) === 1 && ! str_ends_with($this->exchangeInput, ' ') && strlen($firstToken) >= 2) {
            $this->suggestions = $this->findCallsignSuggestions(
                $firstToken,
                $this->operatingSession->band_id,
                $this->operatingSession->mode_id,
                $this->operatingSession->station->event_configuration_id,
                $this->operatingSession->station->is_gota,
            );
        }

        $callsign = $this->extractCallsign();
        if ($callsign === null) {
            return;
        }

        $this->runDuplicateCheck(
            $callsign,
            $this->operatingSession->band_id,
            $this->operatingSession->mode_id,
            $this->operatingSession->station->event_configuration_id,
            $this->operatingSession->band->name ?? '',
            $this->operatingSession->mode->name ?? '',
            $this->operatingSession->station->is_gota,
        );
    }

    public function clearInput(): void
    {
        $this->exchangeInput = '';
        $this->parsePreview = [];
        $this->parseError = '';
        $this->participantWarning = '';
        $this->clearDuplicateState();
    }

    #[On('contact-synced')]
    public function onContactSynced(): void
    {
        $this->operatingSession->refresh();
        $this->refreshBandModeWarning();
        unset($this->recentContacts, $this->earlierContacts);
    }

    #[On('contact-discarded')]
    public function onContactDiscarded(): void
    {
        unset($this->recentContacts);
    }

    public function selectSuggestion(string $exchange): void
    {
        $this->exchangeInput = strtoupper(trim($exchange));
        $this->suggestions = [];
        $this->parseError = '';
        $this->refreshParticipantWarning($this->operatingSession->station->event_configuration_id);

        $callsign = $this->extractCallsign();
        if ($callsign && $this->operatingSession->band_id !== null && $this->operatingSession->mode_id !== null) {
            $this->runDuplicateCheck(
                $callsign,
                $this->operatingSession->band_id,
                $this->operatingSession->mode_id,
                $this->operatingSession->station->event_configuration_id,
                $this->operatingSession->band->name ?? '',
                $this->operatingSession->mode->name ?? '',
                $this->operatingSession->station->is_gota,
            );
        }

        $this->dispatch('suggestion-selected');
    }

    /**
     * End this session. With $removeIfEmpty (the browser has no contacts
     * still queued for it) a session that logged nothing is deleted rather
     * than left behind.
     */
    public function endSession(bool $removeIfEmpty = false): void
    {
        $session = $this->operatingSession;

        app(DuplicateCheckService::class)->locked(
            $session->station->event_configuration_id,
            function () use ($session, $removeIfEmpty): void {
                $session->update(['end_time' => appNow()]);

                if ($removeIfEmpty) {
                    app(EmptySessionPruner::class)->deleteIfEmpty($session);
                }
            },
        );

        if (StationMode::isActive()) {
            StationMode::endSession(request());
            $this->redirectRoute('station-mode.landing');

            return;
        }

        $this->redirect(route('logging.station-select'), navigate: true);
    }

    /**
     * Move to another band and mode: end this session and start a new one
     * on the same station. Returns the new session's id and logging URL for
     * the contact queue, which carries unsynced contacts across before it
     * navigates, or the reason the switch can't happen. With $removeIfEmpty
     * (no contacts still queued for this session) a session that logged
     * nothing is deleted rather than left behind.
     *
     * @return array{ok: bool, session_id?: int, url?: string, message?: string}
     */
    public function switchBandMode(int $bandId, int $modeId, bool $removeIfEmpty = false): array
    {
        $this->operatingSession->refresh();

        if ($this->operatingSession->operator_user_id !== auth()->id()
            || ! StationMode::allowsStation($this->operatingSession->station_id)) {
            abort(403);
        }

        try {
            $session = app(BandModeSwitchService::class)->switchTo($this->operatingSession, $bandId, $modeId, $removeIfEmpty);
        } catch (BandModeSwitchException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }

        return [
            'ok' => true,
            'session_id' => $session->id,
            'url' => route('logging.session', $session),
        ];
    }

    /**
     * Bands an operator can switch to, in band-plan order. A free VHF
     * station only gets 6m and up.
     *
     * @return Collection<int, Band>
     */
    #[Computed]
    public function switchBands(): Collection
    {
        return Band::query()
            ->allowedForFieldDay()
            ->allowedAtStation($this->operatingSession->station)
            ->ordered()
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Mode>
     */
    #[Computed]
    public function switchModes(): Collection
    {
        return Mode::query()->orderBy('id')->get(['id', 'name', 'category']);
    }

    /**
     * Frequency range of each band, so the browser can tell which band a
     * dial frequency is on before it queues a contact.
     *
     * @return array<int, array{name: string, low: float, high: float}>
     */
    #[Computed]
    public function bandRanges(): array
    {
        return app(BandResolverService::class)->rangesFor($this->switchBands);
    }

    public function deleteContact(int $contactId): void
    {
        $this->operatingSession->refresh();

        if ($this->operatingSession->end_time !== null) {
            abort(403);
        }

        $contact = Contact::query()
            ->where('id', $contactId)
            ->where('operating_session_id', $this->operatingSession->id)
            ->first();

        if ($contact === null) {
            abort(403);
        }

        AuditLog::log(
            'contact.deleted',
            auditable: $contact,
            oldValues: [
                'callsign' => $contact->callsign,
                'exchange_class' => $contact->exchange_class,
                'session_id' => $this->operatingSession->id,
            ],
        );

        $dupes = app(DuplicateCheckService::class);
        $dupes->locked($contact->event_configuration_id, function () use ($dupes, $contact) {
            $contact->delete();
            $dupes->reevaluate($contact);
            $this->operatingSession->decrement('qso_count');
        });
        ContactChanged::notify(BroadcastChannels::CONTACT_DELETED, [$contact]);

        $this->operatingSession->refresh();
        unset($this->recentContacts);
    }

    public function restoreContact(int $contactId): void
    {
        $this->operatingSession->refresh();

        if ($this->operatingSession->end_time !== null) {
            abort(403);
        }

        $contact = Contact::onlyTrashed()
            ->where('id', $contactId)
            ->where('operating_session_id', $this->operatingSession->id)
            ->first();

        if ($contact === null) {
            abort(403);
        }

        $dupes = app(DuplicateCheckService::class);
        $dupes->locked($contact->event_configuration_id, function () use ($dupes, $contact) {
            $contact->restore();
            $dupes->reevaluate($contact);
            $this->operatingSession->increment('qso_count');
        });
        ContactChanged::notify(BroadcastChannels::CONTACT_UPDATED, [$contact]);

        AuditLog::log(
            'contact.restored',
            auditable: $contact,
            newValues: [
                'callsign' => $contact->callsign,
                'exchange_class' => $contact->exchange_class,
                'session_id' => $this->operatingSession->id,
            ],
        );

        $this->operatingSession->refresh();
        unset($this->recentContacts);
    }

    /**
     * Save an edit to a recalled contact: its exchange and, when the
     * Advanced Fields are sent, those too. Returns the error when the edit
     * is rejected, or null once it is saved.
     *
     * @param  array<string, mixed>|null  $advanced
     */
    public function updateContact(int $contactId, string $exchangeInput, ?array $advanced = null): ?string
    {
        $this->operatingSession->refresh();

        if ($this->operatingSession->end_time !== null) {
            abort(403);
        }

        $contact = Contact::query()
            ->where('id', $contactId)
            ->where('operating_session_id', $this->operatingSession->id)
            ->first();

        if ($contact === null) {
            abort(403);
        }

        $parsed = app(ExchangeParserService::class)->parse($exchangeInput, $this->eventTypeId());

        if (! $parsed['success']) {
            $this->parseError = $parsed['errors'][0] ?? 'Invalid exchange';

            return $this->parseError;
        }

        $advancedValues = [];
        if ($advanced !== null) {
            $advancedValues = $this->validatedAdvancedFields($advanced);

            if ($advancedValues === null) {
                return $this->parseError;
            }
        }

        $auditedFields = ['callsign', 'exchange_class', 'section_id', ...array_keys($advancedValues)];
        $oldValues = $contact->only($auditedFields);

        $dupes = app(DuplicateCheckService::class);
        $previousKey = $dupes->keyFor($contact);

        $contact->fill([
            'callsign' => $parsed['callsign'],
            'exchange_class' => $parsed['transmitter_count'].$parsed['class_code'],
            'section_id' => $parsed['section_id'],
            ...$advancedValues,
        ]);

        $dupes->save($contact, $previousKey);
        ContactChanged::notify(BroadcastChannels::CONTACT_UPDATED, [$contact]);

        AuditLog::log(
            'contact.updated',
            auditable: $contact,
            oldValues: $oldValues,
            newValues: $contact->only($auditedFields),
        );

        $this->exchangeInput = '';
        $this->parseError = '';
        $this->clearDuplicateState();
        unset($this->recentContacts);

        return null;
    }

    /**
     * The Advanced Fields from a recalled-contact edit, normalized, or null
     * with parseError set when one doesn't pass.
     *
     * @param  array<string, mixed>  $advanced
     * @return array<string, mixed>|null
     */
    protected function validatedAdvancedFields(array $advanced): ?array
    {
        $values = AdvancedContactFields::normalize(
            array_intersect_key($advanced, array_flip(AdvancedContactFields::FIELDS))
        );

        $validator = Validator::make(
            $values,
            AdvancedContactFields::rules(),
            AdvancedContactFields::messages(),
            AdvancedContactFields::attributes(),
        );

        if ($validator->fails()) {
            $this->parseError = $validator->errors()->first();

            return null;
        }

        $band = $this->operatingSession->band;
        $frequencyHz = $values['frequency_hz'] ?? null;

        if ($frequencyHz !== null && $band !== null
            && ! app(BandResolverService::class)->bandContainsFrequencyHz($band, (int) $frequencyHz)) {
            $this->parseError = AdvancedContactFields::formatMhz((int) $frequencyHz)." MHz is not on {$band->name}.";

            return null;
        }

        return $validator->validated();
    }

    /**
     * Event type of the event being logged, so the parser only accepts the
     * class letters this rulebook defines.
     */
    protected function eventTypeId(): ?int
    {
        return $this->operatingSession->station->eventConfiguration?->event?->event_type_id;
    }

    #[Computed]
    public function isGotaStation(): bool
    {
        return $this->operatingSession->station->is_gota;
    }

    #[Computed]
    public function gotaCallsign(): ?string
    {
        return $this->operatingSession->station->eventConfiguration->gota_callsign;
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
    public function clubExchange(): string
    {
        $config = $this->operatingSession->station->eventConfiguration;

        $callsign = $this->isGotaStation
            ? ($config->gota_callsign ?? $config->callsign ?? '?')
            : ($config->callsign ?? '?');
        $transmitterCount = $config->transmitter_count ?? '?';
        $classCode = $config->operatingClass->code ?? '?';
        $sectionCode = $config->section->code ?? '?';

        return "{$callsign} {$transmitterCount}{$classCode} {$sectionCode}";
    }

    #[Computed]
    public function phoneticExchange(): string
    {
        $phonetics = [
            'A' => 'Alpha', 'B' => 'Bravo', 'C' => 'Charlie', 'D' => 'Delta',
            'E' => 'Echo', 'F' => 'Foxtrot', 'G' => 'Golf', 'H' => 'Hotel',
            'I' => 'India', 'J' => 'Juliet', 'K' => 'Kilo', 'L' => 'Lima',
            'M' => 'Mike', 'N' => 'November', 'O' => 'Oscar', 'P' => 'Papa',
            'Q' => 'Quebec', 'R' => 'Romeo', 'S' => 'Sierra', 'T' => 'Tango',
            'U' => 'Uniform', 'V' => 'Victor', 'W' => 'Whiskey', 'X' => 'X-ray',
            'Y' => 'Yankee', 'Z' => 'Zulu',
            '0' => 'Zero', '1' => 'One', '2' => 'Two', '3' => 'Three',
            '4' => 'Four', '5' => 'Five', '6' => 'Six', '7' => 'Seven',
            '8' => 'Eight', '9' => 'Nine',
        ];

        $config = $this->operatingSession->station->eventConfiguration;

        $callsign = strtoupper(
            $this->isGotaStation
                ? ($config->gota_callsign ?? $config->callsign ?? '')
                : ($config->callsign ?? '')
        );
        $transmitterCount = $config->transmitter_count ?? '';
        $classCode = strtoupper($config->operatingClass->code ?? '');
        $sectionCode = strtoupper($config->section->code ?? '');

        $toPhonetic = function (string $str) use ($phonetics): string {
            $words = [];
            foreach (str_split($str) as $char) {
                $words[] = $phonetics[$char] ?? $char;
            }

            return implode(' ', $words);
        };

        $parts = array_filter([
            $toPhonetic($callsign),
            $toPhonetic((string) $transmitterCount).' '.$toPhonetic($classCode),
            $toPhonetic($sectionCode),
        ]);

        return implode(', ', $parts);
    }

    /**
     * Contacts from the sessions this one continues through band/mode
     * switches, newest first, so a switch doesn't empty the log. Shown
     * greyed out and read-only above this session's contacts, under a
     * divider per session. A session started fresh has none.
     *
     * @return EloquentCollection<int, Contact>
     */
    #[Computed]
    public function earlierContacts(): EloquentCollection
    {
        return Contact::query()
            ->whereIn('operating_session_id', $this->operatingSession->switchChainIds())
            ->with(['section', 'operatingSession:id,band_id,mode_id,start_time', 'operatingSession.band:id,name', 'operatingSession.mode:id,name'])
            ->latest('qso_time')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function recentContacts()
    {
        return $this->operatingSession->contacts()
            ->withTrashed()
            ->with('section')
            ->latest('qso_time')
            ->latest('id')
            ->limit(50)
            ->get();
    }

    protected function activeContactCount(): int
    {
        return $this->operatingSession->contacts()->count();
    }

    public function render(): View
    {
        return view('livewire.logging.logging-interface')
            ->layout('layouts.app', ['title' => 'Logging']);
    }
}
