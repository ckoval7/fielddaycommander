<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserInvitation;

/**
 * What InitialSetupService created.
 */
final readonly class CompletedSetup
{
    public function __construct(
        public Organization $organization,
        public User $system,
        public ?UserInvitation $adminInvitation,
    ) {}
}
