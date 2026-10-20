<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Observers;

use App\Enums\NotificationCategory;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Scoring\DomainEvents\QsoLogged;
use App\Scoring\Exceptions\UnknownRuleSet;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContactObserver
{
    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Store zero points on a contact its rulebook gives no credit.
     *
     * Every ingest path prices a contact by mode before it is saved; this is the
     * one place that sees the finished contact (sub-mode, satellite flag, band),
     * so a WFD 2027 FT8 or satellite contact is zeroed here whichever way it
     * arrived. Anything summing `contacts.points` then agrees with the score.
     */
    public function saving(Contact $contact): void
    {
        if ($contact->is_duplicate || (int) $contact->points === 0 || $contact->event_configuration_id === null) {
            return;
        }

        $config = $contact->relationLoaded('eventConfiguration')
            ? $contact->eventConfiguration
            : EventConfiguration::query()->find($contact->event_configuration_id);

        try {
            if ($config?->contactNoCreditReason($contact) !== null) {
                $contact->points = 0;
            }
        } catch (UnknownRuleSet) {
            // No rulebook to judge by: leave the points as priced.
        }
    }

    /**
     * Handle the Contact "created" event.
     *
     * Notifications go out once the surrounding transaction (the event lock
     * held while a contact is dupe-checked and inserted) commits, so the lock
     * is not held while they are sent and a rolled-back contact sends none.
     */
    public function created(Contact $contact): void
    {
        DB::afterCommit(function () use ($contact): void {
            try {
                $this->checkNewSection($contact);
                $this->checkQsoMilestone($contact);
            } catch (\Exception $e) {
                Log::error('Failed to process contact notifications', [
                    'contact_id' => $contact->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * QsoLogged dispatches after commit, so bonuses reconcile once the event
     * lock is released.
     */
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
        if (! $contact->section_id || ! ($contact->eventConfiguration?->isScored() ?? true)) {
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
