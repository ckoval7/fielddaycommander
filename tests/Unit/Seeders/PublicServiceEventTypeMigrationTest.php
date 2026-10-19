<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\EventType;
use Database\Seeders\EventTypeSeeder;

function publicServiceMigration(): object
{
    return require database_path('migrations/2026_10_19_212133_add_is_scored_to_event_types_table.php');
}

test('the migration adds the Public Service type to an already seeded install', function () {
    $this->seed(EventTypeSeeder::class);
    EventType::where('code', 'PS')->delete();

    publicServiceMigration()->down();
    publicServiceMigration()->up();

    expect(EventType::where('code', 'PS')->first()?->isScored())->toBeFalse()
        ->and(EventType::where('code', 'FD')->first()->isScored())->toBeTrue();
});

test('the migration leaves a fresh install for the seeder to fill', function () {
    publicServiceMigration()->down();
    publicServiceMigration()->up();

    expect(EventType::count())->toBe(0);
});
