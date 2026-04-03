<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\User;
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
            ->inReminderWindow()
            ->get();

        if ($entries->isEmpty()) {
            $this->info('No bulletin entries in reminder window.');

            return self::SUCCESS;
        }

        $users = User::excludeSystem()->get();
        $sentCount = 0;
        $windowStart = appNow()->subMinutes(5);
        $windowEnd = appNow();

        foreach ($entries as $entry) {
            $timeFormatted = $entry->scheduled_at->format('Hi').' UTC';

            foreach ($users as $user) {
                $reminderMinutes = $user->getBulletinReminderMinutes();

                foreach ($reminderMinutes as $minutes) {
                    $reminderTime = $entry->scheduled_at->copy()->subMinutes($minutes);

                    if ($reminderTime->lt($windowStart) || $reminderTime->gt($windowEnd)) {
                        continue;
                    }

                    $groupKey = "bulletin_reminder_{$entry->id}_{$minutes}m";

                    $alreadySent = $user->notifications()
                        ->where('data->group_key', $groupKey)
                        ->exists();

                    if ($alreadySent) {
                        continue;
                    }

                    $minuteLabel = $minutes === 1 ? '1 minute' : "{$minutes} minutes";

                    $notificationService->notify(
                        user: $user,
                        category: NotificationCategory::BulletinReminder,
                        title: "W1AW Bulletin in {$minuteLabel}",
                        message: "{$entry->mode_label} on {$entry->frequencies} MHz at {$timeFormatted}",
                        url: "/events/{$activeEvent->id}/w1aw-bulletin",
                        groupKey: $groupKey,
                    );

                    $sentCount++;
                    $this->info("Sent {$minutes}min reminder to {$user->call_sign} for {$entry->source} {$entry->mode_label} at {$timeFormatted}");
                }
            }
        }

        $this->info("Sent {$sentCount} bulletin reminder(s).");

        return self::SUCCESS;
    }
}
