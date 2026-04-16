<?php

use App\Livewire\Scoring;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    // Mark setup as complete
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now()]
    );

    $this->eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);

    // Create reference data needed by factories
    Section::factory()->create(['code' => 'CT', 'name' => 'Connecticut']);

    Band::create([
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

    Mode::create([
        'name' => 'CW',
        'category' => 'CW',
        'points_fd' => 2,
        'points_wfd' => 2,
        'description' => 'Continuous Wave',
    ]);

    Mode::create([
        'name' => 'Phone',
        'category' => 'Phone',
        'points_fd' => 1,
        'points_wfd' => 1,
        'description' => 'Voice modes',
    ]);

    // Create default bonus types for FD
    BonusType::create([
        'event_type_id' => $this->eventType->id,
        'code' => 'emergency_power',
        'name' => 'Emergency Power',
        'description' => '100% emergency power',
        'base_points' => 100,
        'is_per_transmitter' => true,
        'is_active' => true,
    ]);

    BonusType::create([
        'event_type_id' => $this->eventType->id,
        'code' => 'satellite_qso',
        'name' => 'Satellite QSO',
        'description' => 'Complete at least one QSO via satellite',
        'base_points' => 100,
        'is_per_transmitter' => false,
        'is_active' => true,
    ]);

    BonusType::create([
        'event_type_id' => $this->eventType->id,
        'code' => 'public_location',
        'name' => 'Public Location',
        'description' => 'Set up in public place',
        'base_points' => 100,
        'is_per_transmitter' => false,
        'is_active' => true,
    ]);

    BonusType::create([
        'event_type_id' => $this->eventType->id,
        'code' => 'web_submission',
        'name' => 'Web Submission',
        'description' => 'Submit Field Day log via ARRL web submission',
        'base_points' => 50,
        'is_per_transmitter' => false,
        'is_active' => true,
    ]);
});

// Helper to create an active event with a config
function makeActiveEvent(array $configOverrides = []): EventConfiguration
{
    $eventType = EventType::where('code', 'FD')->first();
    $section = Section::first();
    $operatingClass = OperatingClass::first() ?? OperatingClass::create([
        'event_type_id' => $eventType->id,
        'code' => '2A',
        'name' => 'Class 2A',
        'description' => 'Two transmitters',
    ]);

    $event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);

    return EventConfiguration::factory()->create(array_merge([
        'event_id' => $event->id,
        'section_id' => $section->id,
        'operating_class_id' => $operatingClass->id,
        'callsign' => 'W1AW',
        'max_power_watts' => 100,
        'uses_generator' => true,
        'transmitter_count' => 2,
    ], $configOverrides));
}

// ============================================================================
// MOUNT & RENDER
// ============================================================================

it('is accessible at the scoring route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('scoring.index'))
        ->assertOk()
        ->assertSeeLivewire('scoring');
});

it('renders successfully', function () {
    Livewire::test(Scoring::class)->assertOk();
});

it('mounts with active event', function () {
    $config = makeActiveEvent();

    $component = Livewire::test(Scoring::class);

    expect($component->event)->not->toBeNull();
    expect($component->event->id)->toBe($config->event_id);
});

it('mounts with null event when no active event', function () {
    $component = Livewire::test(Scoring::class);

    expect($component->event)->toBeNull();
});

// ============================================================================
// SCORE TOTALS
// ============================================================================

it('computes qsoBasePoints as sum of non-duplicate contact points', function () {
    $config = makeActiveEvent(['max_power_watts' => 200]); // 1x multiplier
    $band = Band::first();
    $mode = Mode::where('name', 'CW')->first();

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => true,
    ]);

    $component = Livewire::test(Scoring::class);

    expect($component->qsoBasePoints)->toBe(6);  // 3 x 2 pts, dupe excluded
});

it('computes powerMultiplier correctly', function () {
    $config = makeActiveEvent([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_generator' => false,
        'uses_commercial_power' => false,
    ]);

    $component = Livewire::test(Scoring::class);

    expect($component->powerMultiplier)->toBe(5);
});

it('computes qsoScore as base points times multiplier', function () {
    $config = makeActiveEvent([
        'max_power_watts' => 50, // 2x
        'uses_generator' => false,
    ]);
    $band = Band::first();
    $mode = Mode::where('name', 'CW')->first();

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(Scoring::class);

    expect($component->qsoScore)->toBe(8); // (2 x 2pts) x 2x
});

// ============================================================================
// QSO BREAKDOWN
// ============================================================================

it('counts total, valid, and duplicate contacts', function () {
    $config = makeActiveEvent();
    $band = Band::first();
    $mode = Mode::first();

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => true,
        'points' => 1,
    ]);

    $component = Livewire::test(Scoring::class);

    expect($component->totalContacts)->toBe(7);
    expect($component->validContacts)->toBe(5);
    expect($component->duplicateCount)->toBe(2);
    expect($component->duplicateRate)->toBe(28.6);
});

it('computes the band/mode grid', function () {
    $config = makeActiveEvent();
    $band = Band::first();
    $cw = Mode::where('name', 'CW')->first();

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $cw->id,
        'points' => 2,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(Scoring::class);

    $cwRow = collect($component->bandModeGrid)
        ->first(fn ($r) => $r['mode']->name === 'CW');

    expect($cwRow)->not->toBeNull();
    expect($cwRow['cells'][$band->id])->toBe(3);
    expect($cwRow['total_count'])->toBe(3);
    expect($cwRow['total_points'])->toBe(6);
});

it('hides VHF/UHF bands with no QSOs from the bands list', function () {
    makeActiveEvent();

    $vhf = Band::create([
        'name' => '2m',
        'meters' => 2,
        'frequency_mhz' => 144.0,
        'is_hf' => false,
        'is_vhf_uhf' => true,
        'is_satellite' => false,
        'allowed_fd' => true,
        'allowed_wfd' => true,
        'sort_order' => 8,
    ]);

    $component = Livewire::test(Scoring::class);

    $bandIds = collect($component->bands)->pluck('id')->all();
    expect($bandIds)->not->toContain($vhf->id);
});

it('shows VHF/UHF bands that have QSOs', function () {
    $config = makeActiveEvent();
    $cw = Mode::where('name', 'CW')->first();

    $vhf = Band::create([
        'name' => '2m',
        'meters' => 2,
        'frequency_mhz' => 144.0,
        'is_hf' => false,
        'is_vhf_uhf' => true,
        'is_satellite' => false,
        'allowed_fd' => true,
        'allowed_wfd' => true,
        'sort_order' => 8,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $vhf->id,
        'mode_id' => $cw->id,
        'points' => 1,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(Scoring::class);

    $bandIds = collect($component->bands)->pluck('id')->all();
    expect($bandIds)->toContain($vhf->id);
});

// ============================================================================
// BONUS LIST
// ============================================================================

it('lists bonuses with verified status', function () {
    $config = makeActiveEvent();
    $bonusType = BonusType::where('event_type_id', $this->eventType->id)
        ->where('code', 'web_submission')
        ->first();

    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonusType->id,
        'is_verified' => true,
        'calculated_points' => $bonusType->base_points,
    ]);

    $component = Livewire::test(Scoring::class);

    $entry = collect($component->bonusList)
        ->first(fn ($b) => $b['type']->id === $bonusType->id);

    expect($entry['status'])->toBe('verified');
    expect($entry['points'])->toBe($bonusType->base_points);
});

it('lists bonuses with claimed-unverified status', function () {
    $config = makeActiveEvent();
    $bonusType = BonusType::where('event_type_id', $this->eventType->id)
        ->where('code', 'web_submission')
        ->first();

    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonusType->id,
        'is_verified' => false,
        'calculated_points' => $bonusType->base_points,
    ]);

    $component = Livewire::test(Scoring::class);

    $entry = collect($component->bonusList)
        ->first(fn ($b) => $b['type']->id === $bonusType->id);

    expect($entry['status'])->toBe('claimed');
});

it('lists unclaimed bonuses', function () {
    makeActiveEvent();

    $component = Livewire::test(Scoring::class);

    $unclaimed = collect($component->bonusList)
        ->filter(fn ($b) => $b['status'] === 'unclaimed');

    expect($unclaimed->count())->toBeGreaterThan(0);
});

// ============================================================================
// BONUS LIST — CLASS ELIGIBILITY FILTERING
// ============================================================================

it('excludes bonus types not applicable to the current operating class', function () {
    // site_responsibilities is eligible for B, C, D, E, F — NOT A
    BonusType::create([
        'event_type_id' => $this->eventType->id,
        'code' => 'site_responsibilities',
        'name' => 'Site Responsibilities',
        'description' => 'Operator assumes all site responsibilities',
        'base_points' => 50,
        'is_per_transmitter' => false,
        'is_active' => true,
        'eligible_classes' => ['B', 'C', 'D', 'E', 'F'],
    ]);

    $config = makeActiveEvent();
    $classA = OperatingClass::first();
    $classA->update(['code' => 'A']);
    $config->update(['operating_class_id' => $classA->id]);

    $component = Livewire::test(Scoring::class);

    $codes = collect($component->bonusList)->pluck('type.code');
    expect($codes)->not->toContain('site_responsibilities');
});

it('includes bonus types applicable to the current operating class', function () {
    BonusType::create([
        'event_type_id' => $this->eventType->id,
        'code' => 'site_responsibilities',
        'name' => 'Site Responsibilities',
        'description' => 'Operator assumes all site responsibilities',
        'base_points' => 50,
        'is_per_transmitter' => false,
        'is_active' => true,
        'eligible_classes' => ['B', 'C', 'D', 'E', 'F'],
    ]);

    $config = makeActiveEvent();
    $classB = OperatingClass::first();
    $classB->update(['code' => 'B']);
    $config->update(['operating_class_id' => $classB->id]);

    $component = Livewire::test(Scoring::class);

    $codes = collect($component->bonusList)->pluck('type.code');
    expect($codes)->toContain('site_responsibilities');
});

it('always includes bonus types with null eligible_classes regardless of class', function () {
    // web_submission has null eligible_classes (created in beforeEach)
    $config = makeActiveEvent();
    $classC = OperatingClass::first();
    $classC->update(['code' => 'C']);
    $config->update(['operating_class_id' => $classC->id]);

    $component = Livewire::test(Scoring::class);

    $codes = collect($component->bonusList)->pluck('type.code');
    expect($codes)->toContain('web_submission');
});

// ============================================================================
// EMERGENCY POWER BONUS
// ============================================================================

it('auto-computes emergency power bonus as 100 pts per transmitter', function () {
    $config = makeActiveEvent([
        'transmitter_count' => 5,
        'uses_commercial_power' => false,
        'uses_generator' => true,
    ]);

    // Set operating class to one eligible for emergency power
    $eligibleClass = OperatingClass::first();
    $eligibleClass->update(['code' => 'A']);
    $config->update(['operating_class_id' => $eligibleClass->id]);
    $config->refresh();

    $component = Livewire::test(Scoring::class);

    $entry = collect($component->bonusList)
        ->first(fn ($b) => $b['type']->code === 'emergency_power');

    expect($entry['status'])->toBe('verified');
    expect($entry['points'])->toBe(500); // 5 transmitters × 100 pts
});

it('caps emergency power bonus at 20 transmitters', function () {
    $config = makeActiveEvent([
        'transmitter_count' => 25,
        'uses_commercial_power' => false,
        'uses_generator' => true,
    ]);

    $eligibleClass = OperatingClass::first();
    $eligibleClass->update(['code' => 'A']);
    $config->update(['operating_class_id' => $eligibleClass->id]);
    $config->refresh();

    $component = Livewire::test(Scoring::class);

    $entry = collect($component->bonusList)
        ->first(fn ($b) => $b['type']->code === 'emergency_power');

    expect($entry['points'])->toBe(2000); // capped at 20 × 100
});

it('does not award emergency power bonus when using commercial power', function () {
    $config = makeActiveEvent([
        'transmitter_count' => 5,
        'uses_commercial_power' => true,
    ]);

    $eligibleClass = OperatingClass::first();
    $eligibleClass->update(['code' => 'A']);
    $config->update(['operating_class_id' => $eligibleClass->id]);
    $config->refresh();

    $component = Livewire::test(Scoring::class);

    $entry = collect($component->bonusList)
        ->first(fn ($b) => $b['type']->code === 'emergency_power');

    expect($entry['status'])->toBe('unclaimed');
    expect($entry['points'])->toBe(0);
});

it('includes emergency power bonus in final score', function () {
    $config = makeActiveEvent([
        'transmitter_count' => 3,
        'uses_commercial_power' => false,
        'uses_generator' => true,
        'max_power_watts' => 200, // 1x multiplier
    ]);

    $eligibleClass = OperatingClass::first();
    $eligibleClass->update(['code' => 'A']);
    $config->update(['operating_class_id' => $eligibleClass->id]);
    $config->refresh();

    $component = Livewire::test(Scoring::class);

    // No QSOs, so final score = bonus only = 300 (3 × 100)
    expect($component->bonusScore)->toBe(300);
    expect($component->finalScore)->toBe(300);
});

// ============================================================================
// SATELLITE QSO BONUS
// ============================================================================

it('auto-computes satellite bonus when satellite contact exists', function () {
    $config = makeActiveEvent();

    $eligibleClass = OperatingClass::first();
    $eligibleClass->update(['code' => 'A']);
    $config->update(['operating_class_id' => $eligibleClass->id]);
    $config->refresh();

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => Band::first()->id,
        'mode_id' => Mode::first()->id,
        'is_satellite' => true,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(Scoring::class);

    $entry = collect($component->bonusList)
        ->first(fn ($b) => $b['type']->code === 'satellite_qso');

    expect($entry['status'])->toBe('verified');
    expect($entry['points'])->toBe(100);
});

it('does not award satellite bonus when no satellite contacts exist', function () {
    $config = makeActiveEvent();

    $eligibleClass = OperatingClass::first();
    $eligibleClass->update(['code' => 'A']);
    $config->update(['operating_class_id' => $eligibleClass->id]);
    $config->refresh();

    $component = Livewire::test(Scoring::class);

    $entry = collect($component->bonusList)
        ->first(fn ($b) => $b['type']->code === 'satellite_qso');

    expect($entry['status'])->toBe('unclaimed');
    expect($entry['points'])->toBe(0);
});

it('does not award satellite bonus for duplicate satellite contacts', function () {
    $config = makeActiveEvent();

    $eligibleClass = OperatingClass::first();
    $eligibleClass->update(['code' => 'A']);
    $config->update(['operating_class_id' => $eligibleClass->id]);
    $config->refresh();

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => Band::first()->id,
        'mode_id' => Mode::first()->id,
        'is_satellite' => true,
        'is_duplicate' => true,
    ]);

    $component = Livewire::test(Scoring::class);

    $entry = collect($component->bonusList)
        ->first(fn ($b) => $b['type']->code === 'satellite_qso');

    expect($entry['status'])->toBe('unclaimed');
    expect($entry['points'])->toBe(0);
});

// ============================================================================
// NOTICES
// ============================================================================

it('generates a warning when duplicate rate exceeds 5%', function () {
    $config = makeActiveEvent();
    $band = Band::first();
    $mode = Mode::first();

    Contact::factory()->count(10)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => true,
        'points' => 1,
    ]);

    $component = Livewire::test(Scoring::class);

    $notice = collect($component->notices)
        ->first(fn ($n) => str_contains($n['message'], 'duplicate rate'));

    expect($notice)->not->toBeNull();
    expect($notice['severity'])->toBe('warning');
    expect($notice['section'])->toBe('qso');
});

it('generates an error for zero-point contacts', function () {
    $config = makeActiveEvent();
    $band = Band::first();
    $mode = Mode::first();

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 0,
    ]);

    $component = Livewire::test(Scoring::class);

    $notice = collect($component->notices)
        ->first(fn ($n) => str_contains($n['message'], '0 points'));

    expect($notice)->not->toBeNull();
    expect($notice['severity'])->toBe('error');
});

it('generates no notices for a clean event', function () {
    makeActiveEvent();

    $component = Livewire::test(Scoring::class);

    expect($component->notices)->toBeEmpty();
});

// ============================================================================
// VIEW — MASTHEAD & EQUATION
// ============================================================================

it('shows callsign and section in the masthead', function () {
    $config = makeActiveEvent(['callsign' => 'W1AW']);

    Livewire::test(Scoring::class)
        ->assertSee('W1AW')
        ->assertSee($config->section->name ?? $config->section->abbreviation ?? '');
});

it('shows all equation terms in the headline', function () {
    $config = makeActiveEvent(['max_power_watts' => 200]); // >100W = 1x
    $band = Band::first();
    $mode = Mode::where('name', 'CW')->first();

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
    ]);

    Livewire::test(Scoring::class)
        ->assertSeeText('QSO Base Pts')
        ->assertSeeText('Power Multi.')
        ->assertSeeText('Final Score')
        ->assertSeeText('4')    // 2 contacts × 2 CW pts = 4 base points
        ->assertSeeText('1×');  // >100W = 1x multiplier
});

it('shows no active event message when no event exists', function () {
    Livewire::test(Scoring::class)
        ->assertSee('No active event');
});

// ============================================================================
// VIEW — POWER MULTIPLIER COLUMN
// ============================================================================

it('shows power multiplier column with reason and source chips', function () {
    makeActiveEvent([
        'max_power_watts' => 50,
        'uses_generator' => false,
        'uses_commercial_power' => false,
    ]);

    Livewire::test(Scoring::class)
        ->assertSeeText('2×')
        ->assertSee('50W')                   // part of the reason sentence
        ->assertSeeText('Power Sources Configured')
        ->assertSeeText('Multiplier Rules');
});

it('shows 5x multiplier in power column for QRP with natural power', function () {
    makeActiveEvent([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_generator' => false,
        'uses_commercial_power' => false,
    ]);

    Livewire::test(Scoring::class)
        ->assertSeeText('5×');
});

// ============================================================================
// VIEW — BONUS COLUMN
// ============================================================================

it('shows bonus column with status chips', function () {
    $config = makeActiveEvent();
    $bonusType = BonusType::where('event_type_id', $this->eventType->id)
        ->where('code', 'web_submission')
        ->first();

    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonusType->id,
        'is_verified' => true,
        'calculated_points' => $bonusType->base_points,
    ]);

    Livewire::test(Scoring::class)
        ->assertSee($bonusType->name)
        ->assertSee('Verified')
        ->assertSeeText('Unclaimed')    // summary grid label
        ->assertSeeText('Claimed');     // summary grid label
});

it('shows QSO column with band/mode counts and stats', function () {
    $config = makeActiveEvent();
    $band = Band::first();
    $cw = Mode::where('name', 'CW')->first();

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $cw->id,
        'points' => 2,
        'is_duplicate' => false,
    ]);

    Livewire::test(Scoring::class)
        ->assertSee($band->name)
        ->assertSee('CW')
        ->assertSeeText('3')
        ->assertSeeText('0.0%')
        ->assertSeeText('Total')
        ->assertSeeText('6');  // grand total points: 3 contacts × 2 CW pts
});

// ============================================================================
// VIEW — CORRECTIONS & NOTICES
// ============================================================================

it('shows corrections box when notices exist', function () {
    $config = makeActiveEvent();
    $band = Band::first();
    $mode = Mode::first();

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'points' => 0,
    ]);

    Livewire::test(Scoring::class)
        ->assertSee('Corrections')
        ->assertSee('0 points');
});

it('does not show corrections box when no notices', function () {
    makeActiveEvent();

    Livewire::test(Scoring::class)
        ->assertDontSee('Corrections');
});
