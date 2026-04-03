<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendBulletinReminders extends Command
{
    protected $signature = 'bulletins:send-reminders';

    protected $description = 'Send notifications for upcoming W1AW bulletin transmissions';

    public function handle(NotificationService $notificationService): int
    {
        $activeEvent = Event::active()->first();

        if (! $activeEvent) {
            $this->info('No active event.');

            return self::SUCCESS;
        }

        $entries = BulletinScheduleEntry::query()
            ->forEvent($activeEvent->id)
            ->pendingNotification()
            ->get();

        if ($entries->isEmpty()) {
            $this->info('No pending bulletin reminders.');

            return self::SUCCESS;
        }

        foreach ($entries as $entry) {
            $timeFormatted = $entry->scheduled_at->format('Hi').' UTC';

            $notificationService->notifyAll(
                category: NotificationCategory::BulletinReminder,
                title: 'W1AW Bulletin in 15 minutes',
                message: "{$entry->mode_label} on {$entry->frequencies} MHz at {$timeFormatted}",
                url: "/events/{$activeEvent->id}/w1aw-bulletin",
                groupKey: "bulletin_reminder_{$entry->id}",
            );

            $entry->update(['notification_sent' => true]);

            $this->info("Sent reminder for {$entry->source} {$entry->mode_label} at {$timeFormatted}");
        }

        $this->info("Sent {$entries->count()} bulletin reminder(s).");

        return self::SUCCESS;
    }
}
