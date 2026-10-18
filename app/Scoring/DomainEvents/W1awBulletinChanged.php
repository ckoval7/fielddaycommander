<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Scoring\DomainEvents;

use App\Models\W1awBulletin;

final class W1awBulletinChanged
{
    public function __construct(
        public readonly W1awBulletin $bulletin,
        public readonly ?int $eventConfigurationId,
    ) {}
}
