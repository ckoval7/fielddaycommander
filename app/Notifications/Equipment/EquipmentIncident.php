<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Notifications\Equipment;

use App\Models\EquipmentEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * High priority notification sent when equipment is lost or damaged.
 *
 * This notification is NOT queued to ensure immediate delivery for urgent incidents.
 * Sends different messages to operators (equipment owners) vs. event managers/admins.
 */
class EquipmentIncident extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  EquipmentEvent  $equipmentEvent  The equipment event with the incident
     * @param  string  $incidentType  The type of incident: 'lost' or 'damaged'
     */
    public function __construct(
        public EquipmentEvent $equipmentEvent,
        public string $incidentType
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
        $incidentLabel = ucfirst($this->incidentType);
        $isOwner = $notifiable->id === $equipment->owner_user_id;

        $message = (new MailMessage)
            ->level('error')
            ->subject("URGENT: Equipment {$incidentLabel} - {$equipment->make} {$equipment->model}");

        $isOwner
            ? $this->buildOwnerMessage($message, $notifiable, $equipment, $event, $incidentLabel)
            : $this->buildManagerMessage($message, $equipment, $event, $incidentLabel);

        $message->action('View Equipment Dashboard', route('events.equipment.dashboard', $event));

        $message->line($isOwner
            ? 'We apologize for this incident and will work with you to resolve it.'
            : 'Please contact the equipment owner as soon as possible to discuss recovery or compensation.');

        return $message;
    }

    /**
     * Build the mail message body for equipment owners.
     */
    protected function buildOwnerMessage(MailMessage $message, object $notifiable, $equipment, $event, string $incidentLabel): void
    {
        $message->greeting("Hello {$notifiable->first_name},")
            ->line("This is an urgent notification regarding your equipment at {$event->name}.")
            ->line("**Equipment {$incidentLabel}**: {$equipment->make} {$equipment->model}")
            ->line('**Type**: '.ucfirst(str_replace('_', ' ', $equipment->type)))
            ->line("**Status**: {$incidentLabel} on {$this->equipmentEvent->status_changed_at->format('M d, Y \a\t g:i A')}")
            ->line('**Event**: '.$event->name);

        if ($this->equipmentEvent->manager_notes) {
            $message->line('**Manager Notes**:')
                ->line($this->equipmentEvent->manager_notes);
        }

        $message->line('Please contact the event organizers immediately to discuss recovery or compensation.');
    }

    /**
     * Build the mail message body for event managers and admins.
     */
    protected function buildManagerMessage(MailMessage $message, $equipment, $event, string $incidentLabel): void
    {
        $message->greeting('Equipment Incident Report')
            ->line("An equipment incident has been reported at {$event->name}.")
            ->line("**Incident Type**: {$incidentLabel}")
            ->line("**Equipment**: {$equipment->make} {$equipment->model}")
            ->line('**Type**: '.ucfirst(str_replace('_', ' ', $equipment->type)))
            ->line('**Serial Number**: '.($equipment->serial_number ?? 'Not recorded'));

        if ($equipment->value_usd) {
            $message->line("**Estimated Value**: \${$equipment->value_usd} USD");
        }

        $message->line("**Status Changed**: {$this->equipmentEvent->status_changed_at->format('M d, Y \a\t g:i A')}");

        $this->appendOwnerContactInfo($message, $equipment);

        if ($this->equipmentEvent->manager_notes) {
            $message->line('**Manager Notes**:')
                ->line($this->equipmentEvent->manager_notes);
        }
    }

    /**
     * Append owner contact information to the message.
     */
    protected function appendOwnerContactInfo(MailMessage $message, $equipment): void
    {
        $owner = $equipment->owner;

        if (! $owner) {
            return;
        }

        $message->line('**Owner Contact Information**:')
            ->line("{$owner->first_name} {$owner->last_name} ({$owner->call_sign})")
            ->line("Email: {$owner->email}");

        if ($equipment->emergency_contact_phone) {
            $message->line("Emergency Contact: {$equipment->emergency_contact_phone}");
        } elseif ($owner->phone) {
            $message->line("Phone: {$owner->phone}");
        }
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
            'incident_type' => $this->incidentType,
            'status' => $this->equipmentEvent->status,
            'status_changed_at' => $this->equipmentEvent->status_changed_at,
        ];
    }
}
