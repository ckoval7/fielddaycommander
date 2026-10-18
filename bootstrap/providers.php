<?php

// SPDX-FileCopyrightText: Taylor Otwell
// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later AND MIT

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    FortifyServiceProvider::class,
];
