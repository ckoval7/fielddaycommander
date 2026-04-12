<?php

namespace App\Livewire\Logging\Concerns;

use App\Models\Contact;
use App\Services\ExchangeParserService;

trait HasContactForm
{
    public string $exchangeInput = '';

    /** @var array<string, mixed> */
    public array $parsePreview = [];

    public string $parseError = '';

    public function clearInput(): void
    {
        $this->exchangeInput = '';
        $this->parsePreview = [];
        $this->parseError = '';
    }

    protected function extractCallsign(): ?string
    {
        return app(ExchangeParserService::class)->extractCallsign($this->exchangeInput);
    }

    protected function parseExchange(): array
    {
        return app(ExchangeParserService::class)->parse($this->exchangeInput);
    }

    /**
     * Find callsigns previously worked in this event on a different band/mode.
     *
     * @return array<int, array{callsign: string, exchange: string, worked_on: string}>
     */
    protected function findCallsignSuggestions(
        string $partial,
        int $bandId,
        int $modeId,
        int $eventConfigId,
        bool $isGotaContact = false,
    ): array {
        $alreadyWorked = Contact::query()
            ->where('event_configuration_id', $eventConfigId)
            ->where('band_id', $bandId)
            ->where('mode_id', $modeId)
            ->where('is_duplicate', false)
            ->where('is_gota_contact', $isGotaContact)
            ->pluck('callsign');

        return Contact::query()
            ->where('event_configuration_id', $eventConfigId)
            ->where('callsign', 'LIKE', strtoupper($partial).'%')
            ->where('is_duplicate', false)
            ->where('is_gota_contact', $isGotaContact)
            ->whereNotIn('callsign', $alreadyWorked)
            ->with(['band:id,name', 'mode:id,name', 'section:id,code'])
            ->latest('qso_time')
            ->get()
            ->groupBy('callsign')
            ->map(function ($contacts, $callsign) {
                $workedOn = $contacts
                    ->map(fn ($c) => $c->band->name.' '.$c->mode->name)
                    ->unique()
                    ->implode(', ');

                return [
                    'callsign' => $callsign,
                    'exchange' => strtoupper(trim(
                        ($contacts->first()->callsign ?? '').' '.
                        ($contacts->first()->exchange_class ?? '').' '.
                        ($contacts->first()->section?->code ?? '')
                    )),
                    'worked_on' => $workedOn,
                ];
            })
            ->values()
            ->take(8)
            ->toArray();
    }
}
