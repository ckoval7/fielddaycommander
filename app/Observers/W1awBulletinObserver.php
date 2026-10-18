<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Observers;

use App\Models\W1awBulletin;
use App\Scoring\DomainEvents\W1awBulletinChanged;

class W1awBulletinObserver
{
    public function saved(W1awBulletin $bulletin): void
    {
        event(new W1awBulletinChanged($bulletin, $bulletin->event_configuration_id));
    }

    public function deleted(W1awBulletin $bulletin): void
    {
        event(new W1awBulletinChanged($bulletin, $bulletin->event_configuration_id));
    }

    public function restored(W1awBulletin $bulletin): void
    {
        event(new W1awBulletinChanged($bulletin, $bulletin->event_configuration_id));
    }
}
