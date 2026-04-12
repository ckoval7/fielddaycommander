<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\ExternalLoggerSetting;
use App\Services\EventContextService;
use App\Services\ExternalLoggerManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;

class ExternalLoggerManagement extends Component
{
    public bool $n1mmEnabled = false;

    public int $n1mmPort = 12060;

    public string $processStatus = 'stopped';

    public ?array $heartbeat = null;

    public bool $wsjtxEnabled = false;

    public int $wsjtxPort = 2237;

    public string $wsjtxProcessStatus = 'stopped';

    public ?array $wsjtxHeartbeat = null;

    public bool $udpAdifEnabled = false;

    public int $udpAdifPort = 2238;

    public string $udpAdifProcessStatus = 'stopped';

    public ?array $udpAdifHeartbeat = null;

    public ?array $lastLog = null;

    public ?array $wsjtxLastLog = null;

    public ?array $udpAdifLastLog = null;

    public ?int $eventConfigId = null;

    public bool $isDemoMode = false;

    private const NO_ACTIVE_EVENT_MESSAGE = 'No active event configuration.';

    public function mount(): void
    {
        $this->isDemoMode = (bool) config('demo.enabled');

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

        $wsjtxSetting = ExternalLoggerSetting::where('event_configuration_id', $config->id)
            ->where('listener_type', 'wsjtx')
            ->first();

        if ($wsjtxSetting) {
            $this->wsjtxEnabled = $wsjtxSetting->is_enabled;
            $this->wsjtxPort = $wsjtxSetting->port;
        }

        $udpAdifSetting = ExternalLoggerSetting::where('event_configuration_id', $config->id)
            ->where('listener_type', 'udp-adif')
            ->first();

        if ($udpAdifSetting) {
            $this->udpAdifEnabled = $udpAdifSetting->is_enabled;
            $this->udpAdifPort = $udpAdifSetting->port;
        }

        $this->refreshStatus();
    }

    public function toggleN1mm(): void
    {
        if ($this->isDemoMode) {
            session()->flash('error', 'UDP listeners cannot be modified in demo mode.');

            return;
        }

        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            session()->flash('error', self::NO_ACTIVE_EVENT_MESSAGE);

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
        if ($this->isDemoMode) {
            return;
        }

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

        if ($this->isDemoMode || $this->eventConfigId === null) {
            return;
        }

        $manager = app(ExternalLoggerManager::class);

        // Auto-restart on crash detection
        if ($this->processStatus === 'crashed') {
            $manager->attemptRestart($this->eventConfigId, 'n1mm');
            $this->refreshStatus();
        }

        if ($this->wsjtxProcessStatus === 'crashed') {
            $manager->attemptRestart($this->eventConfigId, 'wsjtx');
            $this->refreshStatus();
        }

        if ($this->udpAdifProcessStatus === 'crashed') {
            $manager->attemptRestart($this->eventConfigId, 'udp-adif');
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
        if ($this->isDemoMode) {
            return;
        }

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

    public function toggleWsjtx(): void
    {
        if ($this->isDemoMode) {
            session()->flash('error', 'UDP listeners cannot be modified in demo mode.');

            return;
        }

        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            session()->flash('error', self::NO_ACTIVE_EVENT_MESSAGE);

            return;
        }

        $manager = app(ExternalLoggerManager::class);

        $setting = $manager->getSetting($config->id, 'wsjtx');

        if ($this->wsjtxEnabled) {
            $manager->disable($config->id, 'wsjtx');
            $manager->stopProcess($config->id, 'wsjtx');
            $this->wsjtxEnabled = false;

            AuditLog::log('external_logger.disabled', auditable: $setting, newValues: [
                'listener_type' => 'wsjtx',
            ]);
        } else {
            $setting = $manager->enable($config->id, 'wsjtx', $this->wsjtxPort);
            $manager->startProcess($config->id, 'wsjtx');
            $this->wsjtxEnabled = true;

            AuditLog::log('external_logger.enabled', auditable: $setting, newValues: [
                'listener_type' => 'wsjtx',
                'port' => $this->wsjtxPort,
            ]);
        }

        $this->refreshStatus();
    }

    public function restartWsjtxProcess(): void
    {
        if ($this->isDemoMode) {
            return;
        }

        if ($this->eventConfigId === null) {
            return;
        }

        $manager = app(ExternalLoggerManager::class);
        $restarted = $manager->attemptRestart($this->eventConfigId, 'wsjtx');
        $this->refreshStatus();

        if ($restarted) {
            $setting = $manager->getSetting($this->eventConfigId, 'wsjtx');
            AuditLog::log('external_logger.restarted', auditable: $setting, newValues: [
                'listener_type' => 'wsjtx',
            ]);
        }
    }

    public function updateWsjtxPort(): void
    {
        if ($this->isDemoMode) {
            return;
        }

        $this->validate([
            'wsjtxPort' => 'required|integer|min:1024|max:65535',
        ]);

        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            return;
        }

        $setting = ExternalLoggerSetting::where('event_configuration_id', $config->id)
            ->where('listener_type', 'wsjtx')
            ->first();

        if ($setting === null) {
            return;
        }

        $oldPort = $setting->port;
        $setting->update(['port' => $this->wsjtxPort]);

        AuditLog::log('external_logger.port.updated', auditable: $setting, oldValues: [
            'port' => $oldPort,
        ], newValues: [
            'port' => $this->wsjtxPort,
        ]);
    }

    public function toggleUdpAdif(): void
    {
        if ($this->isDemoMode) {
            session()->flash('error', 'UDP listeners cannot be modified in demo mode.');

            return;
        }

        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            session()->flash('error', self::NO_ACTIVE_EVENT_MESSAGE);

            return;
        }

        $manager = app(ExternalLoggerManager::class);

        $setting = $manager->getSetting($config->id, 'udp-adif');

        if ($this->udpAdifEnabled) {
            $manager->disable($config->id, 'udp-adif');
            $manager->stopProcess($config->id, 'udp-adif');
            $this->udpAdifEnabled = false;

            AuditLog::log('external_logger.disabled', auditable: $setting, newValues: [
                'listener_type' => 'udp-adif',
            ]);
        } else {
            $setting = $manager->enable($config->id, 'udp-adif', $this->udpAdifPort);
            $manager->startProcess($config->id, 'udp-adif');
            $this->udpAdifEnabled = true;

            AuditLog::log('external_logger.enabled', auditable: $setting, newValues: [
                'listener_type' => 'udp-adif',
                'port' => $this->udpAdifPort,
            ]);
        }

        $this->refreshStatus();
    }

    public function restartUdpAdifProcess(): void
    {
        if ($this->isDemoMode) {
            return;
        }

        if ($this->eventConfigId === null) {
            return;
        }

        $manager = app(ExternalLoggerManager::class);
        $restarted = $manager->attemptRestart($this->eventConfigId, 'udp-adif');
        $this->refreshStatus();

        if ($restarted) {
            $setting = $manager->getSetting($this->eventConfigId, 'udp-adif');
            AuditLog::log('external_logger.restarted', auditable: $setting, newValues: [
                'listener_type' => 'udp-adif',
            ]);
        }
    }

    public function updateUdpAdifPort(): void
    {
        if ($this->isDemoMode) {
            return;
        }

        $this->validate([
            'udpAdifPort' => 'required|integer|min:1024|max:65535',
        ]);

        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            return;
        }

        $setting = ExternalLoggerSetting::where('event_configuration_id', $config->id)
            ->where('listener_type', 'udp-adif')
            ->first();

        if ($setting === null) {
            return;
        }

        $oldPort = $setting->port;
        $setting->update(['port' => $this->udpAdifPort]);

        AuditLog::log('external_logger.port.updated', auditable: $setting, oldValues: [
            'port' => $oldPort,
        ], newValues: [
            'port' => $this->udpAdifPort,
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
            $this->wsjtxProcessStatus = 'stopped';
            $this->wsjtxHeartbeat = null;
            $this->udpAdifProcessStatus = 'stopped';
            $this->udpAdifHeartbeat = null;
            $this->lastLog = null;
            $this->wsjtxLastLog = null;
            $this->udpAdifLastLog = null;

            return;
        }

        $manager = app(ExternalLoggerManager::class);
        $this->processStatus = $manager->getProcessStatus($this->eventConfigId, 'n1mm');
        $this->heartbeat = $manager->getHeartbeat($this->eventConfigId, 'n1mm');
        $this->wsjtxProcessStatus = $manager->getProcessStatus($this->eventConfigId, 'wsjtx');
        $this->wsjtxHeartbeat = $manager->getHeartbeat($this->eventConfigId, 'wsjtx');
        $this->udpAdifProcessStatus = $manager->getProcessStatus($this->eventConfigId, 'udp-adif');
        $this->udpAdifHeartbeat = $manager->getHeartbeat($this->eventConfigId, 'udp-adif');
        $this->lastLog = Cache::get("external-logger:n1mm:{$this->eventConfigId}:last-log");
        $this->wsjtxLastLog = Cache::get("external-logger:wsjtx:{$this->eventConfigId}:last-log");
        $this->udpAdifLastLog = Cache::get("external-logger:udp-adif:{$this->eventConfigId}:last-log");
    }
}
