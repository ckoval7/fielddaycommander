<?php

namespace App\Livewire\Logging\Concerns;

use App\Services\LoggingWarningService;

trait HasParticipantWarning
{
    public string $participantWarning = '';

    protected function refreshParticipantWarning(int $eventConfigurationId): void
    {
        $this->participantWarning = app(LoggingWarningService::class)
            ->participantWarning($this->extractCallsign(), $eventConfigurationId) ?? '';
    }
}
