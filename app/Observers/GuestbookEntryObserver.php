<?php

namespace App\Observers;

use App\Enums\NotificationCategory;
use App\Models\GuestbookEntry;
use App\Scoring\DomainEvents\GuestbookEntryChanged;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class GuestbookEntryObserver
{
    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Handle the GuestbookEntry "created" event.
     */
    public function created(GuestbookEntry $entry): void
    {
        try {
            $visitorName = trim("{$entry->first_name} {$entry->last_name}");
            $callsign = $entry->callsign ? " ({$entry->callsign})" : '';

            $this->notificationService->notifyAll(
                category: NotificationCategory::Guestbook,
                title: 'New Guestbook Entry',
                message: "{$visitorName}{$callsign} signed the guestbook",
                url: '/guestbook',
                groupKey: 'guestbook_entries',
            );
        } catch (\Exception $e) {
            Log::error('Failed to send guestbook notification', [
                'entry_id' => $entry->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function saved(GuestbookEntry $entry): void
    {
        event(new GuestbookEntryChanged($entry, $entry->event_configuration_id));
    }

    public function deleted(GuestbookEntry $entry): void
    {
        event(new GuestbookEntryChanged($entry, $entry->event_configuration_id));
    }
}
