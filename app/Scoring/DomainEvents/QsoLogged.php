<?php

namespace App\Scoring\DomainEvents;

use App\Models\Contact;

final class QsoLogged
{
    public function __construct(
        public readonly Contact $contact,
        public readonly ?int $eventConfigurationId,
    ) {}
}
