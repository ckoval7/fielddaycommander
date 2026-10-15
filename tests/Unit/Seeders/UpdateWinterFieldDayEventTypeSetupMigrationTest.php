<?php

use App\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'seeders');

const WFD_NEW_DESCRIPTION = 'Winter Field Day Association event - held the 4th full weekend in January';
const WFD_PREVIOUS_DESCRIPTION = 'Winter Field Day Association event - held the last full weekend in January';

/**
 * @return object{up: callable, down: callable}
 */
function winterFieldDaySetupMigration(): object
{
    return require database_path('migrations/2026_10_15_164055_update_winter_field_day_event_type_setup.php');
}

test('migration sets WFD setup offset to 24 hours and updates the description', function () {
    EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => WFD_PREVIOUS_DESCRIPTION,
        'is_active' => true,
        'setup_offset_hours' => null,
    ]);

    winterFieldDaySetupMigration()->up();

    expect(EventType::where('code', 'WFD')->first())
        ->setup_offset_hours->toBe(24)
        ->description->toBe(WFD_NEW_DESCRIPTION);
});

test('migration leaves the FD event type untouched', function () {
    EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
        'setup_offset_hours' => 24,
    ]);

    winterFieldDaySetupMigration()->up();

    expect(EventType::where('code', 'FD')->first())
        ->setup_offset_hours->toBe(24)
        ->description->toBe('ARRL Field Day');
});

test('down restores the previous WFD description and clears the setup offset', function () {
    EventType::create([
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => WFD_NEW_DESCRIPTION,
        'is_active' => true,
        'setup_offset_hours' => 24,
    ]);

    winterFieldDaySetupMigration()->down();

    expect(EventType::where('code', 'WFD')->first())
        ->setup_offset_hours->toBeNull()
        ->description->toBe(WFD_PREVIOUS_DESCRIPTION);
});
