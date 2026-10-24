<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Notifications\Equipment;

use App\Models\EquipmentEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification sent to Event Manager when an operator cancels their equipment commitment.
 *
 * This notification is queued for async delivery.
 */
class EquipmentCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    private const DATE_FORMAT = 'M d, Y \a\t g:i A';

    /**
     * Create a new notification instance.
     *
     * @param  EquipmentEvent  $equipmentEvent  The cancelled equipment event
     */
    public function __construct(
        public EquipmentEvent $equipmentEvent
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

        $message = (new MailMessage)
            ->subject("Equipment Commitment Cancelled: {$equipment->make} {$equipment->model}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("An equipment commitment has been cancelled for {$event->name}.")
            ->line('**Cancelled Equipment**:')
            ->line("{$equipment->make} {$equipment->model}")
            ->line('**Type**: '.ucfirst(str_replace('_', ' ', $equipment->type)));

        if ($equipment->description) {
            $message->line("**Description**: {$equipment->description}");
        }

        $message->line("**Cancelled On**: {$this->equipmentEvent->status_changed_at->format(self::DATE_FORMAT)}")
            ->line("**Originally Committed**: {$this->equipmentEvent->committed_at->format(self::DATE_FORMAT)}");

        if ($this->equipmentEvent->expected_delivery_at) {
            $message->line("**Was Expected**: {$this->equipmentEvent->expected_delivery_at->format(self::DATE_FORMAT)}");
        }

        if ($operator) {
            $message->line('**Owner Contact Information**:')
                ->line("{$operator->first_name} {$operator->last_name} ({$operator->call_sign})")
                ->line("Email: {$operator->email}");

            if ($operator->phone) {
                $message->line("Phone: {$operator->phone}");
            }
        }

        if ($this->equipmentEvent->manager_notes) {
            $message->line('**Manager Notes**:')
                ->line($this->equipmentEvent->manager_notes);
        }

        $message->line('**Impact on Event Planning**:')
            ->line('This cancellation may affect your event setup. You may need to find replacement equipment or adjust your station configuration.');

        $message->action('View Equipment Dashboard', route('events.equipment.dashboard', $event))
            ->line('Review the event commitments on the equipment dashboard.')
            ->line('Please contact the equipment owner to discuss alternatives.');

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
            'status' => 'cancelled',
            'cancelled_at' => $this->equipmentEvent->status_changed_at,
            'was_committed_at' => $this->equipmentEvent->committed_at,
        ];
    }
}
