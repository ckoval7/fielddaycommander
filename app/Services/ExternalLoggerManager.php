<?php

namespace App\Services;

use App\Models\ExternalLoggerSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\php_binary;

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

        // Start as a detached background process. Using exec() with & instead of
        // Symfony Process because its __destruct() kills the child on GC.
        $command = sprintf(
            '%s %s external-logger:%s --event=%d > /dev/null 2>&1 & echo $!',
            escapeshellarg(php_binary()),
            escapeshellarg(base_path('artisan')),
            $listenerType,
            $eventConfigurationId,
        );

        $output = [];
        exec($command, $output);
        $pid = (int) ($output[0] ?? 0);

        if ($pid > 0) {
            $setting->update(['pid' => $pid]);

            return $pid;
        }

        return null;
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
            // PID exists but no heartbeat — verify it's actually our listener
            // process, not a recycled PID from an unrelated process after reboot.
            if ($this->isListenerProcess($setting->pid, $listenerType)) {
                return 'starting';
            }
        }

        // No live listener process found — needs recovery via pollStatus auto-restart.
        return 'crashed';
    }

    /** @return array<string, mixed>|null */
    public function getHeartbeat(int $eventConfigurationId, string $listenerType): ?array
    {
        $key = "external-logger:{$listenerType}:{$eventConfigurationId}:heartbeat";

        return Cache::get($key);
    }

    /**
     * Check that a PID belongs to our artisan listener, not a recycled OS process.
     */
    private function isListenerProcess(int $pid, string $listenerType): bool
    {
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

        if ($cmdline === false) {
            return false;
        }

        return str_contains($cmdline, "external-logger:{$listenerType}");
    }

    public function attemptRestart(int $eventConfigurationId, string $listenerType): bool
    {
        $cooldownKey = "external-logger:{$listenerType}:{$eventConfigurationId}:restart-cooldown";

        if (Cache::has($cooldownKey)) {
            return false;
        }

        // Kill the old process if it's somehow still around
        $this->stopProcess($eventConfigurationId, $listenerType);

        $pid = $this->startProcess($eventConfigurationId, $listenerType);

        if ($pid !== null) {
            Cache::put($cooldownKey, true, 30);

            return true;
        }

        return false;
    }
}
