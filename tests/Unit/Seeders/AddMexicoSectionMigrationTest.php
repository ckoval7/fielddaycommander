<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Section;
use Database\Seeders\SectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'seeders');

/**
 * @return object{up: callable, down: callable}
 */
function mexicoSectionMigration(): object
{
    return require database_path('migrations/2026_10_15_164058_add_mexico_section.php');
}

test('migration creates the MX section with country MX', function () {
    Section::where('code', 'MX')->delete();

    mexicoSectionMigration()->up();

    expect(Section::where('code', 'MX')->first())
        ->name->toBe('Mexico')
        ->region->toBe('DX')
        ->country->toBe('MX')
        ->is_active->toBeTrue();
});

test('migration does not duplicate an existing MX section', function () {
    Section::where('code', 'MX')->delete();
    Section::create(['code' => 'MX', 'name' => 'Mexico', 'region' => 'DX', 'country' => 'MX', 'is_active' => true]);

    mexicoSectionMigration()->up();

    expect(Section::where('code', 'MX')->count())->toBe(1);
});

test('down removes only the MX section', function () {
    Section::create(['code' => 'DX', 'name' => 'DX (International)', 'region' => 'DX', 'country' => 'DX', 'is_active' => true]);
    mexicoSectionMigration()->up();
    $sectionCountBefore = Section::count();

    mexicoSectionMigration()->down();

    expect(Section::where('code', 'MX')->exists())->toBeFalse()
        ->and(Section::count())->toBe($sectionCountBefore - 1)
        ->and(Section::where('code', 'DX')->exists())->toBeTrue();
});

test('section seeder creates the MX section', function () {
    Section::where('code', 'MX')->delete();

    $this->seed(SectionSeeder::class);

    expect(Section::where('code', 'MX')->first())
        ->country->toBe('MX')
        ->region->toBe('DX');
});
