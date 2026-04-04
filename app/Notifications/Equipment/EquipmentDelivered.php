<?php

namespace App\Notifications\Equipment;

use App\Models\EquipmentEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Equipment Delivered notification.
 *
 * Sent when equipment is marked as delivered to an event.
 * Variants: toOperator (confirmation), toManager (daily digest option).
 */
class EquipmentDelivered extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  EquipmentEvent  $equipmentEvent  The equipment commitment
     * @param  string  $recipient  The recipient type: 'operator' or 'manager'
     */
    public function __construct(
        public EquipmentEvent $equipmentEvent,
        public string $recipient = 'operator',
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

        if ($this->recipient === 'operator') {
            return $this->toOperatorMail($notifiable, $equipment, $event, $statusChangedBy);
        }

        return $this->toManagerMail($notifiable, $equipment, $event, $statusChangedBy);
    }

    /**
     * Build the mail message for the operator (confirmation).
     */
    protected function toOperatorMail(object $notifiable, $equipment, $event, $statusChangedBy): MailMessage
    {
        $equipmentName = trim("{$equipment->make} {$equipment->model}");
        if (! $equipmentName) {
            $equipmentName = ucfirst($equipment->type);
        }

        $changedByName = $statusChangedBy ? trim("{$statusChangedBy->first_name} {$statusChangedBy->last_name}") : 'Event Manager';
        $timestamp = $this->equipmentEvent->status_changed_at->format('M j, Y g:i A');

        $url = route('equipment.index');

        return (new MailMessage)
            ->subject("Equipment Delivered: {$equipmentName} at {$event->name}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Your equipment has been marked as delivered at {$event->name}.")
            ->line('')
            ->line('**Equipment Details:**')
            ->line("Make/Model: {$equipmentName}")
            ->line('Type: '.ucfirst($equipment->type))
            ->line('Current Status: '.ucfirst($this->equipmentEvent->status))
            ->line('')
            ->line('**Delivery Information:**')
            ->line("Event: {$event->name}")
            ->line("Marked by: {$changedByName}")
            ->line("Timestamp: {$timestamp}")
            ->line('')
            ->action('View Equipment Dashboard', $url)
            ->line('Thank you for your contribution to our Field Day event!');
    }

    /**
     * Build the mail message for the manager.
     */
    protected function toManagerMail(object $notifiable, $equipment, $event, $statusChangedBy): MailMessage
    {
        $equipmentName = trim("{$equipment->make} {$equipment->model}");
        if (! $equipmentName) {
            $equipmentName = ucfirst($equipment->type);
        }

        $owner = $equipment->owner;
        $ownerName = $owner ? trim("{$owner->first_name} {$owner->last_name}") : 'Unknown';
        $ownerCallsign = $owner?->call_sign ?? 'N/A';
        $changedByName = $statusChangedBy ? trim("{$statusChangedBy->first_name} {$statusChangedBy->last_name}") : 'Event Manager';
        $timestamp = $this->equipmentEvent->status_changed_at->format('M j, Y g:i A');

        $url = route('events.equipment.dashboard', ['event' => $event->id]);

        return (new MailMessage)
            ->subject("Equipment Delivered: {$equipmentName} at {$event->name}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("Equipment has been marked as delivered at {$event->name}.")
            ->line('')
            ->line('**Equipment Details:**')
            ->line("Make/Model: {$equipmentName}")
            ->line('Type: '.ucfirst($equipment->type))
            ->line("Owner: {$ownerName} ({$ownerCallsign})")
            ->line('Current Status: '.ucfirst($this->equipmentEvent->status))
            ->line('')
            ->line('**Delivery Information:**')
            ->line("Marked by: {$changedByName}")
            ->line("Timestamp: {$timestamp}")
            ->line('')
            ->action('View Equipment Dashboard', $url)
            ->line('Equipment is now ready for assignment to stations.');
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
            'recipient' => $this->recipient,
            'status_changed_at' => $this->equipmentEvent->status_changed_at,
        ];
    }
}
