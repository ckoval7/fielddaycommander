<?php

namespace App\Services;

use App\Models\ExternalLoggerSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class ExternalLoggerManager
{
    public function enable(int $eventConfigurationId, string $listenerType, int $port): ExternalLoggerSetting
    {
        return ExternalLoggerSetting::updateOrCreate(
            [
                'event_configuration_id' => $eventConfigurationId,
                'listener_type' => $listenerType,
            ],
            [
                'is_enabled' => true,
                'port' => $port,
            ],
        );
    }

    public function disable(int $eventConfigurationId, string $listenerType): void
    {
        ExternalLoggerSetting::where('event_configuration_id', $eventConfigurationId)
            ->where('listener_type', $listenerType)
            ->update(['is_enabled' => false]);
    }

    public function isEnabled(int $eventConfigurationId, string $listenerType): bool
    {
        return ExternalLoggerSetting::where('event_configuration_id', $eventConfigurationId)
            ->where('listener_type', $listenerType)
            ->where('is_enabled', true)
            ->exists();
    }

    public function getSetting(int $eventConfigurationId, string $listenerType): ?ExternalLoggerSetting
    {
        return ExternalLoggerSetting::where('event_configuration_id', $eventConfigurationId)
            ->where('listener_type', $listenerType)
            ->first();
    }

    /** @return Collection<int, ExternalLoggerSetting> */
    public function getEnabledSettings(string $listenerType): Collection
    {
        return ExternalLoggerSetting::where('listener_type', $listenerType)
            ->where('is_enabled', true)
            ->get();
    }

    public function startProcess(int $eventConfigurationId, string $listenerType): ?int
    {
        $setting = $this->getSetting($eventConfigurationId, $listenerType);
        if ($setting === null) {
            return null;
        }

        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'external-logger:n1mm',
            '--event='.$eventConfigurationId,
        ]);
        $process->setWorkingDirectory(base_path());
        $process->disableOutput();
        $process->start();

        $pid = $process->getPid();
        $setting->update(['pid' => $pid]);

        return $pid;
    }

    public function stopProcess(int $eventConfigurationId, string $listenerType): void
    {
        $setting = $this->getSetting($eventConfigurationId, $listenerType);
        if ($setting === null) {
            return;
        }

        if ($setting->pid !== null && posix_kill($setting->pid, 0)) {
            posix_kill($setting->pid, SIGTERM);
        }

        $setting->update(['pid' => null]);
    }

    public function getProcessStatus(int $eventConfigurationId, string $listenerType): string
    {
        $setting = $this->getSetting($eventConfigurationId, $listenerType);

        if ($setting === null || ! $setting->is_enabled) {
            return 'stopped';
        }

        $heartbeat = $this->getHeartbeat($eventConfigurationId, $listenerType);

        if ($heartbeat !== null) {
            return 'running';
        }

        if ($setting->pid !== null) {
            return 'crashed';
        }

        return 'starting';
    }

    /** @return array<string, mixed>|null */
    public function getHeartbeat(int $eventConfigurationId, string $listenerType): ?array
    {
        $key = "external-logger:{$listenerType}:{$eventConfigurationId}:heartbeat";

        return Cache::get($key);
    }
}
