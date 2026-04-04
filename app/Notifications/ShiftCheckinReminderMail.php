<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShiftCheckinReminderMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Shift $shift,
        public int $minutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->shift->loadMissing('shiftRole');

        $roleName = $this->shift->shiftRole->name;
        $minuteLabel = $this->minutes === 1 ? '1 minute' : "{$this->minutes} minutes";
        $timeRange = $this->shift->start_time->format('H:i').'–'.$this->shift->end_time->format('H:i').' UTC';
        $url = route('schedule.my-shifts');

        return (new MailMessage)
            ->subject("Shift Reminder: {$roleName} in {$minuteLabel}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your **{$roleName}** shift starts in **{$minuteLabel}**.")
            ->line('')
            ->line('**Shift Details:**')
            ->line("Role: {$roleName}")
            ->line("Time: {$timeRange}")
            ->line('')
            ->action('View My Shifts', $url)
            ->line("Don't forget to check in when your shift begins!");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'shift_id' => $this->shift->id,
            'minutes' => $this->minutes,
        ];
    }
}
