<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\ExternalLoggerSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\php_binary;

class ExternalLoggerManager
{
    public function __construct(private ListenerProcessRunner $processRunner = new ListenerProcessRunner) {}

    public function enable(int $eventConfigurationId, string $listenerType, int $port): ExternalLoggerSetting
    {
        // A fresh enable (possibly on a new port) gets an immediate start.
        $this->clearStartupFailure($eventConfigurationId, $listenerType);

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

        $command = sprintf(
            '%s %s external-logger:%s --event=%d > /dev/null 2>&1 & echo $!',
            escapeshellarg(php_binary()),
            escapeshellarg(base_path('artisan')),
            $listenerType,
            $eventConfigurationId,
        );

        $pid = $this->processRunner->spawnDetached($command);

        if ($pid !== null) {
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

        if ($setting->pid !== null && $this->processRunner->isAlive($setting->pid)) {
            $this->processRunner->terminate($setting->pid);
        }

        $setting->update(['pid' => null]);
    }

    public function getProcessStatus(int $eventConfigurationId, string $listenerType): string
    {
        $setting = $this->getSetting($eventConfigurationId, $listenerType);

        if ($setting === null || ! $setting->is_enabled) {
            return 'stopped';
        }

        if ($this->getHeartbeat($eventConfigurationId, $listenerType) !== null) {
            return 'running';
        }

        // PID exists and belongs to our listener: still starting up (no heartbeat yet).
        // Otherwise: crashed — needs recovery via pollStatus auto-restart.
        $isStarting = $setting->pid !== null
            && $this->isListenerProcess($setting->pid, $listenerType);

        return $isStarting ? 'starting' : 'crashed';
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
        $cmdline = $this->processRunner->commandLine($pid);

        if ($cmdline === null) {
            return false;
        }

        return str_contains($cmdline, "external-logger:{$listenerType}");
    }

    /**
     * Longest wait between restarts of a listener that keeps failing to start.
     */
    private const MAX_STARTUP_BACKOFF_SECONDS = 3600;

    /**
     * Record that a listener exited during startup (e.g. the sockets
     * extension is missing or its port is taken), so restarts back off
     * instead of respawning it every minute. Logged once per distinct reason.
     */
    public function recordStartupFailure(int $eventConfigurationId, string $listenerType, string $reason): void
    {
        $key = $this->startupFailureKey($eventConfigurationId, $listenerType);
        $previous = Cache::get($key);
        $attempts = ($previous['attempts'] ?? 0) + 1;

        if (($previous['reason'] ?? null) !== $reason) {
            Log::error("External logger {$listenerType} listener failed to start: {$reason}", [
                'event_configuration_id' => $eventConfigurationId,
                'listener_type' => $listenerType,
            ]);
        }

        Cache::put($key, [
            'reason' => $reason,
            'attempts' => $attempts,
            'failed_at' => now()->getTimestamp(),
        ], now()->addDay());
    }

    public function clearStartupFailure(int $eventConfigurationId, string $listenerType): void
    {
        Cache::forget($this->startupFailureKey($eventConfigurationId, $listenerType));
    }

    /** @return array{reason: string, attempts: int, failed_at: int}|null */
    public function getStartupFailure(int $eventConfigurationId, string $listenerType): ?array
    {
        return Cache::get($this->startupFailureKey($eventConfigurationId, $listenerType));
    }

    /**
     * Whether a listener that failed to start is still inside its backoff
     * window: one minute after the first failure, doubling per failure up to
     * an hour.
     */
    public function isBackingOff(int $eventConfigurationId, string $listenerType): bool
    {
        $failure = $this->getStartupFailure($eventConfigurationId, $listenerType);

        if ($failure === null) {
            return false;
        }

        $backoff = min(self::MAX_STARTUP_BACKOFF_SECONDS, 60 * (2 ** max(0, min(6, $failure['attempts'] - 1))));

        return now()->getTimestamp() < $failure['failed_at'] + $backoff;
    }

    private function startupFailureKey(int $eventConfigurationId, string $listenerType): string
    {
        return "external-logger:{$listenerType}:{$eventConfigurationId}:startup-failure";
    }

    public function attemptRestart(int $eventConfigurationId, string $listenerType): bool
    {
        $cooldownKey = "external-logger:{$listenerType}:{$eventConfigurationId}:restart-cooldown";

        if (Cache::has($cooldownKey) || $this->isBackingOff($eventConfigurationId, $listenerType)) {
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
