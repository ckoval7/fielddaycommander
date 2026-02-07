<?php

namespace App\Livewire\Logging;

use App\Models\Contact;
use App\Models\OperatingSession;
use App\Services\DuplicateCheckService;
use App\Services\ExchangeParserService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class LoggingInterface extends Component
{
    public OperatingSession $operatingSession;

    public string $exchangeInput = '';

    public bool $isDuplicate = false;

    public string $dupeWarning = '';

    /** @var array<string, mixed> */
    public array $parsePreview = [];

    public string $parseError = '';

    /** @var array<int, array{callsign: string, worked_on: string}> */
    public array $suggestions = [];

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
        $this->isDuplicate = false;
        $this->dupeWarning = '';
        $this->parsePreview = [];
        $this->suggestions = [];

        if (trim($this->exchangeInput) === '') {
            return;
        }

        // Show suggestions while typing the first token (callsign)
        $tokens = preg_split('/\s+/', trim($this->exchangeInput));
        $firstToken = strtoupper($tokens[0] ?? '');
        if (count($tokens) === 1 && ! str_ends_with($this->exchangeInput, ' ') && strlen($firstToken) >= 2) {
            $this->suggestions = $this->findCallsignSuggestions($firstToken);
        }

        $parser = app(ExchangeParserService::class);
        $callsign = $parser->extractCallsign($this->exchangeInput);

        if ($callsign === null) {
            return;
        }

        $dupeService = app(DuplicateCheckService::class);
        $dupeCheck = $dupeService->check(
            $callsign,
            $this->operatingSession->band_id,
            $this->operatingSession->mode_id,
            $this->operatingSession->station->event_configuration_id,
        );

        if ($dupeCheck['is_duplicate']) {
            $this->isDuplicate = true;
            $bandName = $this->operatingSession->band->name ?? 'unknown band';
            $modeName = $this->operatingSession->mode->name ?? 'unknown mode';
            $this->dupeWarning = "{$callsign} already worked on {$bandName} {$modeName}";
        }
    }

    public function logContact(): void
    {
        $this->parseError = '';

        if (trim($this->exchangeInput) === '') {
            $this->parseError = 'Exchange is empty';

            return;
        }

        $parser = app(ExchangeParserService::class);
        $parsed = $parser->parse($this->exchangeInput);

        if (! $parsed['success']) {
            $this->parseError = implode('. ', $parsed['errors']);

            return;
        }

        $dupeService = app(DuplicateCheckService::class);
        $dupeCheck = $dupeService->check(
            $parsed['callsign'],
            $this->operatingSession->band_id,
            $this->operatingSession->mode_id,
            $this->operatingSession->station->event_configuration_id,
        );

        $mode = $this->operatingSession->mode;

        Contact::create([
            'event_configuration_id' => $this->operatingSession->station->event_configuration_id,
            'operating_session_id' => $this->operatingSession->id,
            'logger_user_id' => auth()->id(),
            'band_id' => $this->operatingSession->band_id,
            'mode_id' => $this->operatingSession->mode_id,
            'qso_time' => appNow(),
            'callsign' => $parsed['callsign'],
            'section_id' => $parsed['section_id'],
            'received_exchange' => $this->exchangeInput,
            'power_watts' => $this->operatingSession->power_watts,
            'points' => $dupeCheck['is_duplicate'] ? 0 : $mode->points_fd,
            'is_duplicate' => $dupeCheck['is_duplicate'],
            'duplicate_of_contact_id' => $dupeCheck['duplicate_of_contact_id'],
        ]);

        $this->operatingSession->increment('qso_count');

        $this->exchangeInput = '';
        $this->isDuplicate = false;
        $this->dupeWarning = '';
        $this->parsePreview = [];
        $this->parseError = '';
        $this->suggestions = [];

        $this->dispatch('contact-logged');
    }

    public function clearInput(): void
    {
        $this->exchangeInput = '';
        $this->isDuplicate = false;
        $this->dupeWarning = '';
        $this->parsePreview = [];
        $this->parseError = '';
        $this->suggestions = [];
    }

    public function selectSuggestion(string $exchange): void
    {
        $this->exchangeInput = strtoupper(trim($exchange));
        $this->suggestions = [];
        $this->parseError = '';

        $parser = app(ExchangeParserService::class);
        $callsign = $parser->extractCallsign($exchange);

        if ($callsign) {
            $dupeService = app(DuplicateCheckService::class);
            $dupeCheck = $dupeService->check(
                $callsign,
                $this->operatingSession->band_id,
                $this->operatingSession->mode_id,
                $this->operatingSession->station->event_configuration_id,
            );

            $this->isDuplicate = $dupeCheck['is_duplicate'];
            $this->dupeWarning = $dupeCheck['is_duplicate']
                ? "{$callsign} already worked on {$this->operatingSession->band->name} {$this->operatingSession->mode->name}"
                : '';
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

    /**
     * Find callsigns previously worked in this event on a different band/mode.
     *
     * @return array<int, array{callsign: string, exchange: string, worked_on: string}>
     */
    private function findCallsignSuggestions(string $partial): array
    {
        $eventConfigId = $this->operatingSession->station->event_configuration_id;
        $currentBandId = $this->operatingSession->band_id;
        $currentModeId = $this->operatingSession->mode_id;

        // Callsigns already worked on current band+mode (dupes, not suggestions)
        $alreadyWorked = Contact::query()
            ->where('event_configuration_id', $eventConfigId)
            ->where('band_id', $currentBandId)
            ->where('mode_id', $currentModeId)
            ->where('is_duplicate', false)
            ->pluck('callsign');

        return Contact::query()
            ->where('event_configuration_id', $eventConfigId)
            ->where('callsign', 'LIKE', strtoupper($partial).'%')
            ->where('is_duplicate', false)
            ->whereNotIn('callsign', $alreadyWorked)
            ->with(['band:id,name', 'mode:id,name'])
            ->latest('qso_time')
            ->get()
            ->groupBy('callsign')
            ->map(function ($contacts, $callsign) {
                $workedOn = $contacts
                    ->map(fn ($c) => $c->band->name.' '.$c->mode->name)
                    ->unique()
                    ->implode(', ');

                // Use the most recent exchange for this callsign
                $exchange = strtoupper(trim($contacts->first()->received_exchange));

                return [
                    'callsign' => $callsign,
                    'exchange' => $exchange,
                    'worked_on' => $workedOn,
                ];
            })
            ->values()
            ->take(8)
            ->toArray();
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
