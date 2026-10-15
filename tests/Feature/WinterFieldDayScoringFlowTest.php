<?php

use App\Livewire\Scoring;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Scoring\EventBonusReconciler;
use App\Scoring\RuleSetFactory;
use App\Services\CabrilloExporter;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);
uses()->group('feature', 'scoring');

/**
 * End-to-end WFD scoring: log contacts, let the reconciler derive objectives,
 * and check the composed score against the published formula.
 */
beforeEach(function () {
    $this->seed(EventTypeSeeder::class);
    $this->seed(BonusTypeSeeder::class);

    $this->wfd = EventType::where('code', 'WFD')->firstOrFail();

    $class = OperatingClass::create([
        'event_type_id' => $this->wfd->id,
        'code' => 'O',
        'name' => 'Outdoor',
        'allows_gota' => false,
    ]);

    $event = Event::factory()->create([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2026',
    ]);

    $this->config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'operating_class_id' => $class->id,
        'max_power_watts' => 100,
        'uses_commercial_power' => true,
        'has_gota_station' => false,
    ]);

    $this->phone = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->cw = Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
});

function logContacts(EventConfiguration $config, Mode $mode, Band $band, int $count, array $overrides = []): void
{
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'is_gota' => false,
    ]);

    for ($i = 0; $i < $count; $i++) {
        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'is_duplicate' => false,
            'points' => $config->pointsForContact($mode, $station),
            ...$overrides,
        ]);
    }
}

test('a WFD event resolves a ruleset instead of throwing', function () {
    $rules = app(RuleSetFactory::class)->forEvent($this->config->event);

    expect($rules->id())->toBe('WFD-2026');
});

test('contacts score from points_wfd through the event configuration', function () {
    $station = Station::factory()->make(['is_gota' => false]);

    expect($this->config->pointsForContact($this->phone, $station))->toBe(1)
        ->and($this->config->pointsForContact($this->cw, $station))->toBe(2);
});

test('a WFD event has no power multiplier and no GOTA bonus', function () {
    expect($this->config->usesPowerMultiplier())->toBeFalse()
        ->and($this->config->calculatePowerMultiplier())->toBe('1')
        ->and($this->config->calculateGotaBonus())->toBe(0)
        ->and($this->config->calculateGotaCoachBonus())->toBe(0)
        ->and($this->config->calculateYouthBonus())->toBe(0)
        ->and($this->config->calculateEmergencyPowerBonus())->toBe(0);
});

test('the scoring UI is told to use objective wording', function () {
    expect($this->config->nomenclature()->awardPlural)->toBe('Objectives')
        ->and($this->config->nomenclature()->awardSectionTitle)->toBe('Objectives');
});

test('score multiplies QSO points by the derived objective multiplier', function () {
    // Six bands x three Phone contacts each = 18 QSO points, and the six-band
    // (x6) and multiple-modes objectives become reachable.
    foreach (range(1, 6) as $i) {
        $band = Band::factory()->create(['name' => "{$i}m", 'meters' => $i]);
        logContacts($this->config, $this->phone, $band, 3);
    }

    // One CW contact: +2 points and a second mode group for multiple_modes (x2).
    $cwBand = Band::factory()->create(['name' => '40m', 'meters' => 40]);
    logContacts($this->config, $this->cw, $cwBand, 1);

    app(EventBonusReconciler::class)->reconcileAll($this->config);

    $breakdown = $this->config->fresh()->scoreBreakdown();

    // 18 Phone points + 2 CW points = 20 QSO points.
    // Objectives derived: six_bands (6) + multiple_modes (2) = OM 8 -> multiplier 9.
    // Running on commercial mains, so alternative_power is not awarded; 100 W
    // rules out QRP; twelve_bands needs six more bands.
    expect($breakdown->value('qso_points'))->toBe(20)
        ->and($breakdown->value('objective_multiplier'))->toBe(8)
        ->and($breakdown->value('total_multiplier'))->toBe(9)
        ->and($breakdown->total)->toBe(180)
        ->and($this->config->fresh()->calculateFinalScore())->toBe(180);
});

test('satellite QSOs raise no QSO points but the objective still multiplies', function () {
    $band = Band::factory()->create(['name' => '20m', 'meters' => 20]);
    logContacts($this->config, $this->phone, $band, 4);

    $satBand = Band::factory()->create(['name' => '70cm', 'meters' => 0]);
    logContacts($this->config, $this->phone, $satBand, 1, ['is_satellite' => true]);

    $config = $this->config->fresh();

    // The satellite QSO carries points on the row but is excluded from the total.
    expect($config->scoreBreakdown()->value('qso_points'))->toBe(4);

    // Claiming the FM satellite objective (x2) doubles the score instead.
    $fmSatellite = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2026')
        ->where('code', 'fm_satellite')
        ->firstOrFail();

    $config->bonuses()->create([
        'bonus_type_id' => $fmSatellite->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => true,
        'verified_at' => now(),
    ]);

    expect($config->fresh()->scoreBreakdown()->total)->toBe(12);
});

test('the headline equation is the WFD formula, not the Field Day one', function () {
    $breakdown = $this->config->scoreBreakdown();

    $operators = array_values(array_filter($breakdown->headline, 'is_string'));
    $labels = array_map(
        fn ($term) => $term->label,
        array_values(array_filter($breakdown->headline, fn ($p) => ! is_string($p))),
    );

    expect($breakdown->formula)->toBe('QSO Points x (OM + 1)')
        ->and($operators)->toBe(['×'])
        ->and($labels)->toBe(['QSO Points', 'OM + 1']);
});

test('seeded WFD objectives are all multiplier-based, never point-based', function () {
    $objectives = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2026')
        ->where('is_active', true)
        ->get();

    expect($objectives)->toHaveCount(12);

    foreach ($objectives as $objective) {
        expect($objective->objective_multiplier)->not->toBeNull()
            ->and((int) $objective->base_points)->toBe(0);
    }
});

test('Cabrillo export identifies the log as WFD, not ARRL Field Day', function () {
    $section = Section::firstOrCreate(
        ['code' => 'AL'],
        ['name' => 'Alabama', 'region' => 'W4'],
    );

    $this->config->update([
        'section_id' => $section->id,
        'callsign' => 'K4SCO',
        'transmitter_count' => 3,
    ]);

    $config = $this->config->fresh();
    $log = app(CabrilloExporter::class)->export($config);

    expect($log)->toContain('CONTEST: WFD')
        ->and($log)->not->toContain('ARRL-FD')
        // WFDA's format carries the exchange in X-EXCHANGE, not ARRL category lines.
        ->and($log)->toContain('X-EXCHANGE: 3O')
        ->and($log)->not->toContain('CATEGORY-STATION')
        ->and(app(CabrilloExporter::class)->filename($config))
        ->toBe('K4SCO.log');
});

test('the scoring page renders for a WFD event with objective wording', function () {
    $user = User::factory()->create();

    $this->config->update(['is_active' => true]);
    $this->config->event->update(['is_active' => true]);

    $band = Band::factory()->create(['name' => '20m', 'meters' => 20]);
    logContacts($this->config, $this->phone, $band, 3);

    Livewire::actingAs($user)
        ->test(Scoring::class)
        ->assertOk()
        ->assertSee('Objectives')
        ->assertSee('OM + 1')
        ->assertDontSee('Power Multiplier')
        ->assertDontSee('GOTA');
});

test('the scoring page cites WFDA rules rather than ARRL rules', function () {
    $user = User::factory()->create();

    $this->config->update(['is_active' => true]);
    $this->config->event->update(['is_active' => true]);

    $band = Band::factory()->create(['name' => '20m', 'meters' => 20]);
    logContacts($this->config, $this->phone, $band, 3);

    Livewire::actingAs($user)
        ->test(Scoring::class)
        ->assertOk()
        ->assertSee('WFDA Rule Objective &amp; Multipliers:', escape: false)
        ->assertDontSee('ARRL Rule');
});

test('the QSO points column agrees with the headline for satellite QSOs', function () {
    $band = Band::factory()->create(['name' => '20m', 'meters' => 20]);
    logContacts($this->config, $this->phone, $band, 3);

    $satBand = Band::factory()->create(['name' => '70cm', 'meters' => 0]);
    logContacts($this->config, $this->phone, $satBand, 2, ['is_satellite' => true]);

    $user = User::factory()->create();
    $this->config->update(['is_active' => true]);
    $this->config->event->update(['is_active' => true]);

    $component = Livewire::actingAs($user)->test(Scoring::class);

    // Both figures must exclude the two satellite QSOs.
    expect($component->get('qsoBasePoints'))->toBe(3)
        ->and($this->config->fresh()->scoreBreakdown()->value('qso_points'))->toBe(3);
});

test('the ARRL submission sheet is not offered for a WFD event', function () {
    DB::table('system_config')->insertOrIgnore([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Permission::firstOrCreate(['name' => 'view-reports']);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->givePermissionTo('view-reports');

    $this->config->update(['is_active' => true]);
    $this->config->event->update(['is_active' => true]);

    expect($this->config->fresh()->usesArrlSubmissionSheet())->toBeFalse();

    // The route is gated too, not just the card — an authorised user still
    // cannot pull a Field Day entry form for a Winter Field Day event.
    $this->actingAs($user)
        ->get(route('reports.submission-sheet'))
        ->assertNotFound();
});

test('the scoring page shows objective completion as WFDA tracks it', function () {
    Permission::firstOrCreate(['name' => 'view-reports']);
    $user = User::factory()->create();

    $this->config->update(['is_active' => true]);
    $this->config->event->update(['is_active' => true]);

    $band = Band::factory()->create(['name' => '20m', 'meters' => 20]);
    logContacts($this->config, $this->phone, $band, 3);

    // Three of the twelve objectives achieved = 25%.
    foreach (['away_from_home', 'winlink_email', 'special_bulletin'] as $code) {
        $objective = BonusType::where('event_type_id', $this->wfd->id)
            ->where('rules_version', '2026')
            ->where('code', $code)
            ->firstOrFail();

        $this->config->bonuses()->create([
            'bonus_type_id' => $objective->id,
            'quantity' => 1,
            'calculated_points' => 0,
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    $component = Livewire::actingAs($user)->test(Scoring::class);

    expect($component->get('objectiveProgress'))
        ->toBe(['achieved' => 3, 'available' => 12, 'percent' => 25]);

    $component->assertSee('Objectives Completed')->assertSee('3/12');
});

test('a WFD 2027 event resolves the 2027 ruleset and scores its objectives', function () {
    $this->config->event->update(['rules_version' => '2027']);
    $config = $this->config->fresh();

    expect(app(RuleSetFactory::class)->forEvent($config->event)->id())->toBe('WFD-2027')
        ->and($config->usesArrlSubmissionSheet())->toBeFalse()
        ->and($config->nomenclature()->awardPlural)->toBe('Objectives');
});

test('the scoring checklist shows station-equipment power as verified when 100% is claimed', function () {
    $user = User::factory()->create();

    $this->config->update(['is_active' => true]);
    $this->config->event->update(['is_active' => true, 'rules_version' => '2027']);

    $band = Band::factory()->create(['name' => '20m', 'meters' => 20]);
    logContacts($this->config, $this->phone, $band, 3);

    $full = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2027')
        ->where('code', 'alt_power_full')
        ->firstOrFail();

    $this->config->bonuses()->create([
        'bonus_type_id' => $full->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => true,
        'verified_at' => now(),
    ]);

    $component = Livewire::actingAs($user)->test(Scoring::class);

    $statuses = collect($component->get('bonusList'))
        ->mapWithKeys(fn (array $item) => [$item['type']->code => $item['status']]);

    // Score: 3 QSO points x (OM 3 + 1). The checklist must agree with it.
    expect($statuses['alt_power_full'])->toBe('verified')
        ->and($statuses['alt_power_equipment'])->toBe('verified')
        ->and($component->get('bonusSummary')['verified_multiplier'])->toBe(3)
        ->and($component->get('objectiveProgress'))->toBe(['achieved' => 2, 'available' => 13, 'percent' => 15])
        ->and($this->config->fresh()->scoreBreakdown()->total)->toBe(12);
});

test('WFD 2027 logs WSJT-mode and satellite contacts but gives them no credit', function () {
    $user = User::factory()->create();

    $this->config->update(['is_active' => true]);
    $this->config->event->update(['is_active' => true, 'rules_version' => '2027']);
    $config = $this->config->fresh();

    $digital = Mode::create(['name' => 'Digital', 'category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    $band = Band::factory()->create(['name' => '20m', 'meters' => 20]);
    logContacts($config, $this->phone, $band, 3, ['submode' => 'USB']);
    logContacts($config, $digital, $band, 2, ['submode' => 'FT8']);
    logContacts($config, $this->phone, $band, 1, ['submode' => 'FM', 'is_satellite' => true]);

    expect($config->contacts()->count())->toBe(6)
        ->and((int) $config->contacts()->sum('points'))->toBe(3)
        ->and($config->creditableContacts()->count())->toBe(3)
        ->and($config->scoreBreakdown()->value('qso_points'))->toBe(3)
        ->and($config->scoreBreakdown()->value('no_credit_contacts'))->toBe(3);

    $multipleModes = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2027')
        ->where('code', 'multiple_modes')
        ->firstOrFail();

    expect($config->bonuses()->where('bonus_type_id', $multipleModes->id)->where('is_verified', true)->exists())->toBeFalse();

    $component = Livewire::actingAs($user)->test(Scoring::class);

    expect($component->get('noCreditContactCount'))->toBe(3);
    $component->assertSee('No Credit');
});
