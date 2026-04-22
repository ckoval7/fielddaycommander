<?php

namespace App\Scoring\DomainEvents;

use App\Models\Message;

final class MessageChanged
{
    public function __construct(
        public readonly Message $message,
        public readonly ?int $eventConfigurationId,
    ) {}
}
