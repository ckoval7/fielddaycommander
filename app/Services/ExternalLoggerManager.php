<?php

namespace App\Services;

use App\Models\ExternalLoggerSetting;
use Illuminate\Support\Collection;

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
}
