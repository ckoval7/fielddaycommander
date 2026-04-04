<?php

namespace App\Notifications\Equipment;

use App\Models\EquipmentEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Equipment Committed notification.
 *
 * Sent when an operator commits equipment to an event.
 * Two variants: toOperator (confirmation) and toManager (alert).
 */
class EquipmentCommitted extends Notification implements ShouldQueue
{
    use Queueable;

    private const DATE_FORMAT = 'M j, Y g:i A';

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
        $operator = $equipment->owner;

        if ($this->recipient === 'operator') {
            return $this->toOperatorMail($notifiable, $equipment, $event);
        }

        return $this->toManagerMail($notifiable, $equipment, $event, $operator);
    }

    /**
     * Build the mail message for the operator (confirmation).
     */
    protected function toOperatorMail(object $notifiable, $equipment, $event): MailMessage
    {
        $equipmentName = trim("{$equipment->make} {$equipment->model}");
        if (! $equipmentName) {
            $equipmentName = ucfirst($equipment->type);
        }

        $url = route('equipment.index');

        return (new MailMessage)
            ->subject("Equipment Committed: {$equipmentName} to {$event->name}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("You have successfully committed your equipment to {$event->name}.")
            ->line('')
            ->line('**Equipment Details:**')
            ->line("Make/Model: {$equipmentName}")
            ->line('Type: '.ucfirst($equipment->type))
            ->line('')
            ->line('**Event Details:**')
            ->line("Event: {$event->name}")
            ->line("Dates: {$event->start_time->format(self::DATE_FORMAT)} - {$event->end_time->format(self::DATE_FORMAT)}")
            ->lineIf($this->equipmentEvent->expected_delivery_at, "Expected Delivery: {$this->equipmentEvent->expected_delivery_at?->format(self::DATE_FORMAT)}")
            ->lineIf($this->equipmentEvent->delivery_notes, "Delivery Notes: {$this->equipmentEvent->delivery_notes}")
            ->line('')
            ->action('Manage Your Commitments', $url)
            ->line('Thank you for contributing to our Field Day event!');
    }

    /**
     * Build the mail message for the manager (alert).
     */
    protected function toManagerMail(object $notifiable, $equipment, $event, $operator): MailMessage
    {
        $equipmentName = trim("{$equipment->make} {$equipment->model}");
        if (! $equipmentName) {
            $equipmentName = ucfirst($equipment->type);
        }

        $operatorName = $operator ? trim("{$operator->first_name} {$operator->last_name}") : 'Unknown';
        $operatorCallsign = $operator?->call_sign ?? 'N/A';

        $url = route('events.equipment.dashboard', ['event' => $event->id]);

        return (new MailMessage)
            ->subject("New Equipment Committed by {$operatorCallsign}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("{$operatorName} ({$operatorCallsign}) has committed equipment to {$event->name}.")
            ->line('')
            ->line('**Equipment Details:**')
            ->line("Make/Model: {$equipmentName}")
            ->line('Type: '.ucfirst($equipment->type))
            ->line("Owner: {$operatorName} ({$operatorCallsign})")
            ->line('')
            ->line('**Event Details:**')
            ->line("Event: {$event->name}")
            ->lineIf($this->equipmentEvent->expected_delivery_at, "Expected Delivery: {$this->equipmentEvent->expected_delivery_at?->format(self::DATE_FORMAT)}")
            ->lineIf($this->equipmentEvent->delivery_notes, "Delivery Notes: {$this->equipmentEvent->delivery_notes}")
            ->line('')
            ->action('View Equipment Dashboard', $url)
            ->line('Please coordinate with the operator for equipment delivery and setup.');
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
        ];
    }
}
