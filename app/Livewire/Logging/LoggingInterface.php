<?php

namespace App\Livewire\Logging;

use App\Livewire\Logging\Concerns\HasContactForm;
use App\Livewire\Logging\Concerns\HasDuplicateDetection;
use App\Models\OperatingSession;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class LoggingInterface extends Component
{
    use HasContactForm, HasDuplicateDetection;

    public OperatingSession $operatingSession;

    public function mount(OperatingSession $operatingSession): void
    {
        if ($operatingSession->operator_user_id !== auth()->id()) {
            abort(403);
        }

        if ($operatingSession->end_time !== null) {
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
        );
    }

    public function logContact(): void
    {
        $this->parseError = '';

        if (trim($this->exchangeInput) === '') {
            $this->parseError = 'Exchange is empty';

            return;
        }

        $parsed = $this->parseExchange();

        if (! $parsed['success']) {
            $this->parseError = implode('. ', $parsed['errors']);

            return;
        }

        // Dispatch to browser for client-side queueing and async sync
        $this->dispatch('contact-queued',
            band_id: $this->operatingSession->band_id,
            mode_id: $this->operatingSession->mode_id,
            callsign: $parsed['callsign'],
            section_id: $parsed['section_id'],
            section_code: $parsed['section_code'],
            received_exchange: $this->exchangeInput,
            power_watts: $this->operatingSession->power_watts,
        );

        $this->clearInput();
        $this->clearDuplicateState();

        $this->dispatch('contact-logged');
    }

    public function clearInput(): void
    {
        $this->exchangeInput = '';
        $this->parsePreview = [];
        $this->parseError = '';
        $this->clearDuplicateState();
    }

    #[\Livewire\Attributes\On('contact-synced')]
    public function onContactSynced(): void
    {
        unset($this->recentContacts);
    }

    #[\Livewire\Attributes\On('contact-discarded')]
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
            );
        }

        $this->dispatch('suggestion-selected');
    }

    public function endSession(): void
    {
        $this->operatingSession->update(['end_time' => appNow()]);

        $this->redirect(route('logging.station-select'), navigate: true);
    }

    #[Computed]
    public function clubExchange(): string
    {
        $config = $this->operatingSession->station->eventConfiguration;

        $callsign = $config->callsign ?? '?';
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

        $callsign = strtoupper($config->callsign ?? '');
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
