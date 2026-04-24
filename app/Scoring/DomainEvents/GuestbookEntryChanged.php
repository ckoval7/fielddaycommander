<?php

namespace App\Scoring\DomainEvents;

use App\Models\GuestbookEntry;

final class GuestbookEntryChanged
{
    public function __construct(
        public readonly GuestbookEntry $entry,
        public readonly ?int $eventConfigurationId,
    ) {}
}
