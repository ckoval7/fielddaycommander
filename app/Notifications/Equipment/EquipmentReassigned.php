<?php

namespace App\Notifications\Equipment;

use App\Models\Equipment;
use App\Models\Station;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Equipment Reassigned notification.
 *
 * Sent to the previous station manager when equipment is reassigned
 * from one station to another during the same event.
 */
class EquipmentReassigned extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  Equipment  $equipment  The equipment being reassigned
     * @param  Station  $oldStation  The previous station the equipment was assigned to
     * @param  Station  $newStation  The new station the equipment is assigned to
     * @param  User  $reassignedBy  The user who performed the reassignment
     * @param  string|null  $reason  Optional reason for the reassignment
     */
    public function __construct(
        public Equipment $equipment,
        public Station $oldStation,
        public Station $newStation,
        public User $reassignedBy,
        public ?string $reason = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $equipmentName = trim("{$this->equipment->make} {$this->equipment->model}");
        if (! $equipmentName) {
            $equipmentName = ucfirst($this->equipment->type);
        }

        $reassignedByName = trim("{$this->reassignedBy->first_name} {$this->reassignedBy->last_name}");
        $reassignedByCallsign = $this->reassignedBy->call_sign ?? 'N/A';
        $timestamp = now()->format('M j, Y g:i A');

        $url = route('stations.index');

        $message = (new MailMessage)
            ->subject("Equipment Reassigned: {$equipmentName}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line('Equipment has been reassigned between stations.')
            ->line('')
            ->line('**Equipment Details:**')
            ->line("Make/Model: {$equipmentName}")
            ->line('Type: '.ucfirst($this->equipment->type));

        // Add value if available
        if ($this->equipment->value_usd) {
            $message->line("Estimated Value: \${$this->equipment->value_usd}");
        }

        $message->line('')
            ->line('**Reassignment Details:**')
            ->line("Previous Station: {$this->oldStation->name}")
            ->line("New Station: {$this->newStation->name}")
            ->line("Reassigned by: {$reassignedByName} ({$reassignedByCallsign})")
            ->line("Timestamp: {$timestamp}");

        // Add reason if provided
        if ($this->reason) {
            $message->line('')
                ->line('**Reason:**')
                ->line($this->reason);
        }

        $message->line('')
            ->action('View Station Equipment', $url)
            ->line('Please coordinate with the new station to ensure proper equipment handoff.');

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'equipment_id' => $this->equipment->id,
            'equipment_name' => "{$this->equipment->make} {$this->equipment->model}",
            'old_station_id' => $this->oldStation->id,
            'old_station_name' => $this->oldStation->name,
            'new_station_id' => $this->newStation->id,
            'new_station_name' => $this->newStation->name,
            'reassigned_by_user_id' => $this->reassignedBy->id,
            'reassigned_at' => now(),
            'reason' => $this->reason,
        ];
    }
}
