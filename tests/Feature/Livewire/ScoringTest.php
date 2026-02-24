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
use Livewire\Livewire;

beforeEach(function () {
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

// ============================================================================
// BONUS LIST
// ============================================================================

it('lists bonuses with verified status', function () {
    $config = makeActiveEvent();
    $bonusType = BonusType::where('event_type_id', $this->eventType->id)
        ->where('is_active', true)
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
        ->where('is_active', true)
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
