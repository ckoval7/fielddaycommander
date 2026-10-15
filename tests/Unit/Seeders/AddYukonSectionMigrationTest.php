<?php

use App\Models\Section;
use Database\Seeders\SectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'seeders');

/**
 * @return object{up: callable, down: callable}
 */
function yukonSectionMigration(): object
{
    return require database_path('migrations/2026_10_15_173428_add_yukon_section.php');
}

test('migration creates the YT section with country CA', function () {
    Section::where('code', 'YT')->delete();

    yukonSectionMigration()->up();

    expect(Section::where('code', 'YT')->first())
        ->name->toBe('Yukon')
        ->region->toBe('VE')
        ->country->toBe('CA')
        ->is_active->toBeTrue();
});

test('migration does not duplicate an existing YT section', function () {
    Section::where('code', 'YT')->delete();
    Section::create(['code' => 'YT', 'name' => 'Yukon', 'region' => 'VE', 'country' => 'CA', 'is_active' => true]);

    yukonSectionMigration()->up();

    expect(Section::where('code', 'YT')->count())->toBe(1);
});

test('down removes only the YT section', function () {
    Section::create(['code' => 'TER', 'name' => 'Territories', 'region' => 'VE', 'country' => 'CA', 'is_active' => true]);
    yukonSectionMigration()->up();
    $sectionCountBefore = Section::count();

    yukonSectionMigration()->down();

    expect(Section::where('code', 'YT')->exists())->toBeFalse()
        ->and(Section::count())->toBe($sectionCountBefore - 1)
        ->and(Section::where('code', 'TER')->exists())->toBeTrue();
});

test('section seeder creates the YT section', function () {
    Section::where('code', 'YT')->delete();

    $this->seed(SectionSeeder::class);

    expect(Section::where('code', 'YT')->first())
        ->country->toBe('CA')
        ->region->toBe('VE');
});
