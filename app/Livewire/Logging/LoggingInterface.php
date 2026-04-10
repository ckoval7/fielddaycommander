<?php

namespace App\Livewire\Logging;

use App\Livewire\Logging\Concerns\HasContactForm;
use App\Livewire\Logging\Concerns\HasDuplicateDetection;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Models\User;
use App\Services\ExchangeParserService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class LoggingInterface extends Component
{
    use HasContactForm, HasDuplicateDetection;

    public OperatingSession $operatingSession;

    public string $gotaOperatorFirstName = '';

    public string $gotaOperatorLastName = '';

    public string $gotaOperatorCallsign = '';

    public ?int $gotaOperatorUserId = null;

    public string $gotaUserSearch = '';

    public function mount(OperatingSession $operatingSession): void
    {
        if ($operatingSession->operator_user_id !== auth()->id()) {
            abort(403);
        }

        if ($operatingSession->end_time !== null) {
            $this->redirect(route('logging.station-select'), navigate: true);

            return;
        }

        $event = $operatingSession->station->eventConfiguration?->event;
        if (! $event || Carbon::parse($event->end_time)->lt(appNow())) {
            $operatingSession->update(['end_time' => $event?->end_time ?? appNow()]);
            $this->redirect(route('logging.station-select'), navigate: true);

            return;
        }

        $this->operatingSession = $operatingSession;
    }

    public function updatedExchangeInput(): void
    {
        $this->parseError = '';
        $this->clearDuplicateState();
        $this->parsePreview = [];

        if (trim($this->exchangeInput) === '') {
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
        $this->clearDuplicateState();
    }

    #[On('contact-synced')]
    public function onContactSynced(): void
    {
        $this->operatingSession->refresh();
        unset($this->recentContacts);
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

        $callsign = $this->extractCallsign();
        if ($callsign) {
            $this->runDuplicateCheck(
                $callsign,
                $this->operatingSession->band_id,
                $this->operatingSession->mode_id,
                $this->operatingSession->station->event_configuration_id,
                $this->operatingSession->band->name,
                $this->operatingSession->mode->name,
                $this->operatingSession->station->is_gota,
            );
        }

        $this->dispatch('suggestion-selected');
    }

    public function endSession(): void
    {
        $this->operatingSession->update(['end_time' => appNow()]);

        $this->redirect(route('logging.station-select'), navigate: true);
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
                'received_exchange' => $contact->received_exchange,
                'session_id' => $this->operatingSession->id,
            ],
        );

        $contact->delete();
        $this->operatingSession->decrement('qso_count');

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

        $contact->restore();
        $this->operatingSession->increment('qso_count');

        AuditLog::log(
            'contact.restored',
            auditable: $contact,
            newValues: [
                'callsign' => $contact->callsign,
                'received_exchange' => $contact->received_exchange,
                'session_id' => $this->operatingSession->id,
            ],
        );

        $this->operatingSession->refresh();
        unset($this->recentContacts);
    }

    public function updateContact(int $contactId, string $exchangeInput): void
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

        $parsed = app(ExchangeParserService::class)->parse($exchangeInput);

        if (! $parsed['success']) {
            $this->parseError = $parsed['errors'][0] ?? 'Invalid exchange';

            return;
        }

        $oldValues = [
            'callsign' => $contact->callsign,
            'received_exchange' => $contact->received_exchange,
            'section_id' => $contact->section_id,
        ];

        $contact->update([
            'callsign' => $parsed['callsign'],
            'received_exchange' => strtoupper(trim($exchangeInput)),
            'section_id' => $parsed['section_id'],
        ]);

        // Re-run duplicate detection, excluding this contact itself
        $isDuplicate = Contact::query()
            ->where('event_configuration_id', $contact->event_configuration_id)
            ->where('band_id', $contact->band_id)
            ->where('mode_id', $contact->mode_id)
            ->where('callsign', $parsed['callsign'])
            ->where('is_gota_contact', $contact->is_gota_contact)
            ->where('id', '!=', $contact->id)
            ->where('is_duplicate', false)
            ->exists();

        $contact->update([
            'is_duplicate' => $isDuplicate,
            'points' => $isDuplicate ? 0 : ($contact->operatingSession->mode->points_fd ?? 1),
        ]);

        AuditLog::log(
            'contact.updated',
            auditable: $contact,
            oldValues: $oldValues,
            newValues: [
                'callsign' => $contact->callsign,
                'received_exchange' => $contact->received_exchange,
                'section_id' => $contact->section_id,
            ],
        );

        $this->exchangeInput = '';
        $this->parseError = '';
        $this->clearDuplicateState();
        unset($this->recentContacts);
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

    #[Computed]
    public function recentContacts()
    {
        return $this->operatingSession->contacts()
            ->withTrashed()
            ->with('section')
            ->latest('qso_time')
            ->limit(50)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.logging.logging-interface')
            ->layout('layouts.app');
    }
}
