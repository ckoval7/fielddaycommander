<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShiftCheckoutReminderMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Shift $shift,
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
        $endTime = $this->shift->end_time->format('H:i').' UTC';
        $url = route('schedule.my-shifts');

        return (new MailMessage)
            ->subject("Check-out reminder: {$roleName}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your **{$roleName}** shift ended at **{$endTime}** and you haven't checked out yet.")
            ->line('')
            ->action('Check Out Now', $url)
            ->line('Please check out so your hours are recorded correctly.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'shift_id' => $this->shift->id,
        ];
    }
}
