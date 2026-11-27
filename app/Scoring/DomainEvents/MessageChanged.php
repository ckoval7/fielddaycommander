<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\DomainEvents;

use App\Models\Message;

final class MessageChanged
{
    public function __construct(
        public readonly Message $message,
        public readonly ?int $eventConfigurationId,
    ) {}
}
