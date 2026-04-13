<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ExternalLoggerSetting;
use App\Services\ExternalLoggerManager;
use Illuminate\Console\Command;

class MonitorExternalListenersCommand extends Command
{
    protected $signature = 'external-logger:monitor';

    protected $description = 'Monitor external logger listeners and restart any that have crashed';

    public function handle(ExternalLoggerManager $manager): int
    {
        $enabledSettings = ExternalLoggerSetting::where('is_enabled', true)->get();

        if ($enabledSettings->isEmpty()) {
            return self::SUCCESS;
        }

        $restarted = 0;

        foreach ($enabledSettings as $setting) {
            $status = $manager->getProcessStatus($setting->event_configuration_id, $setting->listener_type);

            if ($status === 'crashed') {
                $wasRestarted = $manager->attemptRestart($setting->event_configuration_id, $setting->listener_type);

                if ($wasRestarted) {
                    $restarted++;
                    $this->info("Restarted crashed {$setting->listener_type} listener for event {$setting->event_configuration_id}");

                    AuditLog::log('external_logger.auto_restarted', auditable: $setting, newValues: [
                        'listener_type' => $setting->listener_type,
                        'reason' => 'crashed',
                    ]);
                }
            }
        }

        if ($restarted > 0) {
            $this->info("Restarted {$restarted} crashed listener(s).");
        }

        return self::SUCCESS;
    }
}
