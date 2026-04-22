<?php

namespace App\Observers;

use App\Enums\NotificationCategory;
use App\Models\Contact;
use App\Scoring\DomainEvents\QsoLogged;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class ContactObserver
{
    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Handle the Contact "created" event.
     */
    public function created(Contact $contact): void
    {
        try {
            $this->checkNewSection($contact);
            $this->checkQsoMilestone($contact);
        } catch (\Exception $e) {
            Log::error('Failed to process contact notifications', [
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function saved(Contact $contact): void
    {
        event(new QsoLogged($contact, $contact->event_configuration_id));
    }

    public function deleted(Contact $contact): void
    {
        event(new QsoLogged($contact, $contact->event_configuration_id));
    }

    /**
     * Check if this contact's section is new for the event.
     */
    protected function checkNewSection(Contact $contact): void
    {
        if (! $contact->section_id) {
            return;
        }

        $eventConfigId = $contact->event_configuration_id;

        $previouslySeen = Contact::where('event_configuration_id', $eventConfigId)
            ->where('section_id', $contact->section_id)
            ->where('id', '!=', $contact->id)
            ->exists();

        if ($previouslySeen) {
            return;
        }

        $section = $contact->section;
        $sectionName = $section?->code ?? 'Unknown';
        $band = $contact->band?->name ?? '';
        $mode = $contact->mode?->name ?? '';
        $opCall = $contact->operatingSession?->operator?->call_sign ?? 'Unknown';

        $this->notificationService->notifyAll(
            category: NotificationCategory::NewSection,
            title: 'New Section Worked!',
            message: "{$opCall} worked {$sectionName} on {$band} {$mode}",
            url: '/logbook',
            groupKey: "new_section_event_{$eventConfigId}",
        );
    }

    /**
     * Check if the total QSO count has reached a milestone (every 50).
     */
    protected function checkQsoMilestone(Contact $contact): void
    {
        $eventConfigId = $contact->event_configuration_id;

        $totalQsos = Contact::where('event_configuration_id', $eventConfigId)
            ->where('is_duplicate', false)
            ->count();

        if ($totalQsos > 0 && $totalQsos % 50 === 0) {
            $this->notificationService->notifyAll(
                category: NotificationCategory::QsoMilestone,
                title: 'QSO Milestone!',
                message: "Congratulations! {$totalQsos} QSOs logged for this event!",
                url: '/logbook',
                groupKey: "qso_milestone_{$totalQsos}",
            );
        }
    }
}
