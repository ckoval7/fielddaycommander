<?php

namespace App\Scoring\DomainEvents;

use App\Models\W1awBulletin;

final class W1awBulletinChanged
{
    public function __construct(
        public readonly W1awBulletin $bulletin,
        public readonly ?int $eventConfigurationId,
    ) {}
}
