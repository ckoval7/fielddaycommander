<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\User;
use Illuminate\Support\Facades\Blade;

test('user menu links to my shifts', function () {
    $this->actingAs(User::factory()->create());

    $html = Blade::render('<x-user-menu />');

    expect($html)
        ->toContain('My Shifts')
        ->toContain(route('schedule.my-shifts'));
});
