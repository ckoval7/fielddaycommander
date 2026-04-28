<?php

use App\Livewire\Reports\ReportsIndex;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function makeReportsIndexEvent(array $configOverrides = []): EventConfiguration
{
    DB::table('system_config')->insertOrIgnore([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $section = Section::factory()->create(['code' => 'CT']);
    $eventType = EventType::factory()->create(['code' => 'FD', 'name' => 'Field Day', 'is_active' => true]);
    $opClass = OperatingClass::create([
        'code' => '2A',
        'event_type_id' => $eventType->id,
        'name' => 'Class 2A',
        'description' => 'Two transmitters',
        'allows_gota' => false,
        'allows_free_vhf' => false,
        'requires_emergency_power' => false,
    ]);

    $event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    return EventConfiguration::factory()->create(array_merge([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'club_name' => 'Anytown ARC',
        'section_id' => $section->id,
        'operating_class_id' => $opClass->id,
        'max_power_watts' => 100,
    ], $configOverrides));
}

// ============================================================================
// MOUNT & RENDER
// ============================================================================

test('component renders for authenticated user with view-reports permission', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $user = User::factory()->create();
    $user->givePermissionTo('view-reports');

    Livewire::actingAs($user)
        ->test(ReportsIndex::class)
        ->assertOk();
});

test('shows no active event text when no active event exists', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $user = User::factory()->create();
    $user->givePermissionTo('view-reports');

    Livewire::actingAs($user)
        ->test(ReportsIndex::class)
        ->assertSee('No active event');
});

test('shows download link text when event is active', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $user = User::factory()->create();
    $user->givePermissionTo('view-reports');

    makeReportsIndexEvent();

    Livewire::actingAs($user)
        ->test(ReportsIndex::class)
        ->assertSee('Cabrillo Log')
        ->assertSee('Submission Sheet');
});

// ============================================================================
// COMPUTED PROPERTIES
// ============================================================================

test('qsoRateByHour returns row with total 3 for an hour with 3 contacts', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $user = User::factory()->create();
    $user->givePermissionTo('view-reports');

    $config = makeReportsIndexEvent();

    $band = Band::first() ?? Band::create([
        'name' => '20m',
        'meters' => 20,
        'frequency_mhz' => 14.0,
        'is_hf' => true,
        'is_vhf_uhf' => false,
        'is_satellite' => false,
        'allowed_fd' => true,
        'allowed_wfd' => true,
        'sort_order' => 4,
    ]);

    $mode = Mode::first() ?? Mode::create([
        'name' => 'CW',
        'category' => 'CW',
        'points_fd' => 2,
        'points_wfd' => 2,
        'description' => 'Continuous Wave',
    ]);

    $fixedTime = now()->startOfHour();

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'qso_time' => $fixedTime,
        'is_duplicate' => false,
        'points' => 2,
    ]);

    $component = Livewire::actingAs($user)->test(ReportsIndex::class);

    $rows = $component->get('qsoRateByHour');

    $matchingRow = collect($rows)->first(fn ($r) => $r['total'] === 3);

    expect($matchingRow)->not->toBeNull();
    expect($matchingRow['total'])->toBe(3);
});

test('operatorSummary returns valid_qsos 5 for operator with callsign W1OP', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $config = makeReportsIndexEvent();

    $band = Band::first() ?? Band::create([
        'name' => '20m',
        'meters' => 20,
        'frequency_mhz' => 14.0,
        'is_hf' => true,
        'is_vhf_uhf' => false,
        'is_satellite' => false,
        'allowed_fd' => true,
        'allowed_wfd' => true,
        'sort_order' => 4,
    ]);

    $mode = Mode::first() ?? Mode::create([
        'name' => 'CW',
        'category' => 'CW',
        'points_fd' => 2,
        'points_wfd' => 2,
        'description' => 'Continuous Wave',
    ]);

    $operator = User::factory()->create(['call_sign' => 'W1OP']);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'logger_user_id' => $operator->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);

    $operator->givePermissionTo('view-reports');

    $component = Livewire::actingAs($operator)->test(ReportsIndex::class);

    $summary = $component->get('operatorSummary');

    $row = collect($summary)->first(fn ($r) => $r['call_sign'] === 'W1OP');

    expect($row)->not->toBeNull();
    expect($row['valid_qsos'])->toBe(5);
});

test('sectionCounts returns count 4 for section code ME', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $user = User::factory()->create();
    $user->givePermissionTo('view-reports');

    $config = makeReportsIndexEvent();

    $band = Band::first() ?? Band::create([
        'name' => '20m',
        'meters' => 20,
        'frequency_mhz' => 14.0,
        'is_hf' => true,
        'is_vhf_uhf' => false,
        'is_satellite' => false,
        'allowed_fd' => true,
        'allowed_wfd' => true,
        'sort_order' => 4,
    ]);

    $mode = Mode::first() ?? Mode::create([
        'name' => 'CW',
        'category' => 'CW',
        'points_fd' => 2,
        'points_wfd' => 2,
        'description' => 'Continuous Wave',
    ]);

    $section = Section::factory()->create(['code' => 'ME', 'name' => 'Maine']);

    Contact::factory()->count(4)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);

    $component = Livewire::actingAs($user)->test(ReportsIndex::class);

    $counts = $component->get('sectionCounts');

    $row = collect($counts)->first(fn ($r) => $r['code'] === 'ME');

    expect($row)->not->toBeNull();
    expect($row['count'])->toBe(4);
});

test('reports volunteerHours aggregates hours worked per volunteer', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-reports');

    $config = makeReportsIndexEvent();
    $role = ShiftRole::factory()->create(['event_configuration_id' => $config->id]);

    $alice = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Adams']);
    $bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Baker']);

    // Alice: 2-hour shift fully worked
    $s1 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->subHours(5),
        'end_time' => now()->subHours(3),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s1->id,
        'user_id' => $alice->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $s1->start_time,
        'checked_out_at' => $s1->end_time,
    ]);

    // Alice: 3-hour upcoming signup (no hours worked yet)
    $s2 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(5),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s2->id,
        'user_id' => $alice->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    // Bob: 1-hour shift, checked out early at 30min
    $s3 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s3->id,
        'user_id' => $bob->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $s3->start_time,
        'checked_out_at' => $s3->start_time->copy()->addMinutes(30),
    ]);

    $component = Livewire::actingAs($viewer)->test(ReportsIndex::class);
    $rows = $component->instance()->volunteerHours();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['name'])->toBe('Alice Adams');
    expect($rows[0]['hours_worked'])->toBe(2.0);
    expect($rows[0]['hours_signed_up'])->toBe(5.0);
    expect($rows[1]['name'])->toBe('Bob Baker');
    expect($rows[1]['hours_worked'])->toBe(0.5);
    expect($rows[1]['hours_signed_up'])->toBe(1.0);
});

test('reports volunteerHours excludes no-show assignments', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-reports');

    $config = makeReportsIndexEvent();
    $role = ShiftRole::factory()->create(['event_configuration_id' => $config->id]);
    $user = User::factory()->create(['first_name' => 'Carol', 'last_name' => 'Cole']);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subHours(1),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'status' => ShiftAssignment::STATUS_NO_SHOW,
    ]);

    $component = Livewire::actingAs($viewer)->test(ReportsIndex::class);

    expect($component->instance()->volunteerHours())->toBeEmpty();
});

test('reports page renders volunteer hours card section', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-reports');

    $config = makeReportsIndexEvent();
    $role = ShiftRole::factory()->create(['event_configuration_id' => $config->id]);
    $user = User::factory()->create(['first_name' => 'Dana', 'last_name' => 'Drew']);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subHour(),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $shift->start_time,
        'checked_out_at' => $shift->end_time,
    ]);

    Livewire::actingAs($viewer)
        ->test(ReportsIndex::class)
        ->assertSee('Volunteer Hours')
        ->assertSee('Dana Drew')
        ->assertSeeHtml('2.0');
});

test('reports volunteerHoursTotals sums hours worked and scheduled across all volunteers', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-reports');

    $config = makeReportsIndexEvent();
    $role = ShiftRole::factory()->create(['event_configuration_id' => $config->id]);

    $alice = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Adams']);
    $bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Baker']);

    // Alice: 2-hour shift fully worked
    $s1 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->subHours(5),
        'end_time' => now()->subHours(3),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s1->id,
        'user_id' => $alice->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $s1->start_time,
        'checked_out_at' => $s1->end_time,
    ]);

    // Alice: 3-hour upcoming signup
    $s2 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->addHours(2),
        'end_time' => now()->addHours(5),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s2->id,
        'user_id' => $alice->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    // Bob: 1-hour shift, checked out at 30min
    $s3 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s3->id,
        'user_id' => $bob->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $s3->start_time,
        'checked_out_at' => $s3->start_time->copy()->addMinutes(30),
    ]);

    $totals = Livewire::actingAs($viewer)->test(ReportsIndex::class)->instance()->volunteerHoursTotals();

    expect($totals['hours_worked'])->toBe(2.5);
    expect($totals['hours_signed_up'])->toBe(6.0);
    expect($totals['volunteer_count'])->toBe(2);
});

test('reports page renders volunteer hours totals summary', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-reports');

    $config = makeReportsIndexEvent();
    $role = ShiftRole::factory()->create(['event_configuration_id' => $config->id]);
    $user = User::factory()->create(['first_name' => 'Dana', 'last_name' => 'Drew']);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subHour(),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $shift->start_time,
        'checked_out_at' => $shift->end_time,
    ]);

    Livewire::actingAs($viewer)
        ->test(ReportsIndex::class)
        ->assertSee('Total Hours Worked')
        ->assertSee('Total Hours Scheduled');
});

test('reports volunteerHours merges overlapping shifts in wall_clock mode', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view-reports');

    Setting::set('volunteer_hours_mode', 'wall_clock');

    $config = makeReportsIndexEvent();
    $role = ShiftRole::factory()->create(['event_configuration_id' => $config->id]);

    $alice = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Adams']);

    // Three overlapping 4h / 2h / 1h shifts (10-14, 11-13, 12-13). Sum=7h, wall-clock=4h.
    $base = now()->subHours(8)->startOfHour();

    $s1 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => $base,
        'end_time' => $base->copy()->addHours(4),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s1->id,
        'user_id' => $alice->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $s1->start_time,
        'checked_out_at' => $s1->end_time,
    ]);

    $s2 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => $base->copy()->addHour(),
        'end_time' => $base->copy()->addHours(3),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s2->id,
        'user_id' => $alice->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $s2->start_time,
        'checked_out_at' => $s2->end_time,
    ]);

    $s3 = Shift::factory()->create([
        'event_configuration_id' => $config->id,
        'shift_role_id' => $role->id,
        'start_time' => $base->copy()->addHours(2),
        'end_time' => $base->copy()->addHours(3),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $s3->id,
        'user_id' => $alice->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => $s3->start_time,
        'checked_out_at' => $s3->end_time,
    ]);

    $rows = Livewire::actingAs($viewer)->test(ReportsIndex::class)->instance()->volunteerHours();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['hours_worked'])->toBe(4.0);
    expect($rows[0]['hours_signed_up'])->toBe(4.0);
});
