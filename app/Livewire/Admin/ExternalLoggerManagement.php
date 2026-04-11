<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\ExternalLoggerSetting;
use App\Services\EventContextService;
use App\Services\ExternalLoggerManager;
use Illuminate\View\View;
use Livewire\Component;

class ExternalLoggerManagement extends Component
{
    public bool $n1mmEnabled = false;

    public int $n1mmPort = 12060;

    public string $processStatus = 'stopped';

    public ?array $heartbeat = null;

    public ?int $eventConfigId = null;

    public function mount(): void
    {
        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            return;
        }

        $this->eventConfigId = $config->id;

        $setting = ExternalLoggerSetting::where('event_configuration_id', $config->id)
            ->where('listener_type', 'n1mm')
            ->first();

        if ($setting) {
            $this->n1mmEnabled = $setting->is_enabled;
            $this->n1mmPort = $setting->port;
        }

        $this->refreshStatus();
    }

    public function toggleN1mm(): void
    {
        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            session()->flash('error', 'No active event configuration.');

            return;
        }

        $manager = app(ExternalLoggerManager::class);

        $setting = $manager->getSetting($config->id, 'n1mm');

        if ($this->n1mmEnabled) {
            $manager->disable($config->id, 'n1mm');
            $manager->stopProcess($config->id, 'n1mm');
            $this->n1mmEnabled = false;

            AuditLog::log('external_logger.disabled', auditable: $setting, newValues: [
                'listener_type' => 'n1mm',
            ]);
        } else {
            $setting = $manager->enable($config->id, 'n1mm', $this->n1mmPort);
            $manager->startProcess($config->id, 'n1mm');
            $this->n1mmEnabled = true;

            AuditLog::log('external_logger.enabled', auditable: $setting, newValues: [
                'listener_type' => 'n1mm',
                'port' => $this->n1mmPort,
            ]);
        }

        $this->refreshStatus();
    }

    public function restartProcess(): void
    {
        if ($this->eventConfigId === null) {
            return;
        }

        $manager = app(ExternalLoggerManager::class);
        $restarted = $manager->attemptRestart($this->eventConfigId, 'n1mm');
        $this->refreshStatus();

        if ($restarted) {
            $setting = $manager->getSetting($this->eventConfigId, 'n1mm');
            AuditLog::log('external_logger.restarted', auditable: $setting, newValues: [
                'listener_type' => 'n1mm',
            ]);
        }
    }

    public function pollStatus(): void
    {
        $this->refreshStatus();

        // Auto-restart on crash detection
        if ($this->processStatus === 'crashed' && $this->eventConfigId !== null) {
            $manager = app(ExternalLoggerManager::class);
            $manager->attemptRestart($this->eventConfigId, 'n1mm');
            $this->refreshStatus();
        }
    }

    /** @return array<string, string> */
    protected function getListeners(): array
    {
        if ($this->eventConfigId === null) {
            return [];
        }

        return [
            "echo-private:event.{$this->eventConfigId}.external-logger,ExternalLoggerStatusChanged" => 'handleStatusChanged',
        ];
    }

    public function handleStatusChanged(): void
    {
        $this->refreshStatus();
    }

    public function updatePort(): void
    {
        $this->validate([
            'n1mmPort' => 'required|integer|min:1024|max:65535',
        ]);

        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            return;
        }

        $setting = ExternalLoggerSetting::where('event_configuration_id', $config->id)
            ->where('listener_type', 'n1mm')
            ->first();

        if ($setting === null) {
            return;
        }

        $oldPort = $setting->port;
        $setting->update(['port' => $this->n1mmPort]);

        AuditLog::log('external_logger.port.updated', auditable: $setting, oldValues: [
            'port' => $oldPort,
        ], newValues: [
            'port' => $this->n1mmPort,
        ]);
    }

    public function render(): View
    {
        $config = app(EventContextService::class)->getEventConfiguration();

        return view('livewire.admin.external-logger-management', [
            'hasActiveEvent' => $config !== null,
        ])->layout('components.layouts.app');
    }

    private function refreshStatus(): void
    {
        if ($this->eventConfigId === null) {
            $this->processStatus = 'stopped';
            $this->heartbeat = null;

            return;
        }

        $manager = app(ExternalLoggerManager::class);
        $this->processStatus = $manager->getProcessStatus($this->eventConfigId, 'n1mm');
        $this->heartbeat = $manager->getHeartbeat($this->eventConfigId, 'n1mm');
    }
}
