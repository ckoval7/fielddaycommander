<?php

namespace App\Notifications\Equipment;

use App\Models\EquipmentEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Equipment Status Changed notification.
 *
 * Sent when equipment status changes to returned.
 * Includes old/new status comparison and relevant details.
 */
class EquipmentStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  EquipmentEvent  $equipmentEvent  The equipment commitment
     * @param  string  $previousStatus  The previous status before change
     */
    public function __construct(
        public EquipmentEvent $equipmentEvent,
        public string $previousStatus,
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
        $equipment = $this->equipmentEvent->equipment;
        $event = $this->equipmentEvent->event;
        $statusChangedBy = $this->equipmentEvent->statusChangedBy;
        $station = $this->equipmentEvent->station;

        $equipmentName = trim("{$equipment->make} {$equipment->model}");
        if (! $equipmentName) {
            $equipmentName = ucfirst($equipment->type);
        }

        $changedByName = $statusChangedBy ? trim("{$statusChangedBy->first_name} {$statusChangedBy->last_name}") : 'Event Manager';
        $timestamp = $this->equipmentEvent->status_changed_at->format('M j, Y g:i A');
        $currentStatus = ucfirst(str_replace('_', ' ', $this->equipmentEvent->status));
        $oldStatus = ucfirst(str_replace('_', ' ', $this->previousStatus));

        $url = route('equipment.events');

        $message = (new MailMessage)
            ->subject("Equipment Status Update: {$equipmentName} - {$currentStatus}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("The status of your equipment at {$event->name} has been updated.")
            ->line('')
            ->line('**Equipment Details:**')
            ->line("Make/Model: {$equipmentName}")
            ->line('Type: '.ucfirst($equipment->type))
            ->line('')
            ->line('**Status Change:**')
            ->line("Previous Status: {$oldStatus}")
            ->line("New Status: {$currentStatus}")
            ->line("Changed by: {$changedByName}")
            ->line("Timestamp: {$timestamp}");

        // Add station assignment details if station is assigned
        if ($station) {
            $message->line('')
                ->line('**Station Assignment:**')
                ->line("Station: {$station->name}")
                ->line("Assigned by: {$changedByName}");
        }

        // Add manager notes if present
        if ($this->equipmentEvent->manager_notes) {
            $message->line('')
                ->line('**Manager Notes:**')
                ->line($this->equipmentEvent->manager_notes);
        }

        $message->line('')
            ->action('View Commitment Details', $url);

        // Add contextual footer based on status
        if ($this->equipmentEvent->status === 'returned') {
            $message->line('Thank you for your contribution to our Field Day event!');
        } elseif ($this->equipmentEvent->status === 'delivered') {
            $message->line('Your equipment has been delivered and is ready for use at the event.');
        }

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
            'equipment_event_id' => $this->equipmentEvent->id,
            'equipment_id' => $this->equipmentEvent->equipment_id,
            'event_id' => $this->equipmentEvent->event_id,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->equipmentEvent->status,
            'status_changed_at' => $this->equipmentEvent->status_changed_at,
        ];
    }
}
