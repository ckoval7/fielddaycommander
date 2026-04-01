<?php

namespace App\Livewire\Logging\Concerns;

use App\Services\DuplicateCheckService;

trait HasDuplicateDetection
{
    public bool $isDuplicate = false;

    public string $dupeWarning = '';

    /** @var array<int, array{callsign: string, exchange: string, worked_on: string}> */
    public array $suggestions = [];

    public function clearDuplicateState(): void
    {
        $this->isDuplicate = false;
        $this->dupeWarning = '';
        $this->suggestions = [];
    }

    protected function runDuplicateCheck(
        string $callsign,
        int $bandId,
        int $modeId,
        int $eventConfigId,
        string $bandName = '',
        string $modeName = '',
        bool $isGotaContact = false,
    ): void {
        $result = app(DuplicateCheckService::class)->check($callsign, $bandId, $modeId, $eventConfigId, $isGotaContact);

        $this->isDuplicate = $result['is_duplicate'];
        $this->dupeWarning = $result['is_duplicate']
            ? "{$callsign} already worked on {$bandName} {$modeName}"
            : '';
    }
}
