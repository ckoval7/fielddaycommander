<?php

namespace App\Console\Commands;

use App\Contracts\ReminderSource;
use App\Services\Reminders\BulletinReminderSource;
use App\Services\Reminders\ShiftReminderSource;
use App\Services\ReminderService;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Send notifications for all registered reminder sources';

    /**
     * @var array<class-string<ReminderSource>>
     */
    private array $sources = [
        BulletinReminderSource::class,
        ShiftReminderSource::class,
    ];

    public function handle(ReminderService $reminderService): int
    {
        $totalSent = 0;

        foreach ($this->sources as $sourceClass) {
            $source = app($sourceClass);
            $count = $reminderService->processSource($source);
            $label = class_basename($sourceClass);

            if ($count > 0) {
                $this->info("Sent {$count} reminder(s) via {$label}.");
            }

            $totalSent += $count;
        }

        if ($totalSent === 0) {
            $this->info('No reminders to send.');
        }

        return self::SUCCESS;
    }
}
