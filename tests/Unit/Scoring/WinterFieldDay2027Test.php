<?php

use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\Station;
use App\Scoring\Contracts\FieldDayRuleSet;
use App\Scoring\Contracts\ImpliesObjectives;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\DomainEvents\QsoLogged;
use App\Scoring\Rules\WinterFieldDay2026;
use App\Scoring\Rules\WinterFieldDay2027;
use App\Scoring\RuleSetFactory;
use Database\Seeders\BonusTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'scoring');

/**
 * Every assertion here is taken from the WFDA "Winter Field Day 2027" rules,
 * Version 3, 04 OCT 2026.
 */
beforeEach(function () {
    $this->wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
    $this->rules = new WinterFieldDay2027;
});

function wfd2027Config(EventType $wfd): EventConfiguration
{
    $event = Event::factory()->create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2027',
    ]);

    return EventConfiguration::factory()->create(['event_id' => $event->id]);
}

function wfd2027Objective(EventType $wfd, string $code, int $multiplier): BonusType
{
    return BonusType::create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2027',
        'code' => $code,
        'name' => $code,
        'trigger_type' => 'manual',
        'base_points' => 0,
        'objective_multiplier' => $multiplier,
        'max_occurrences' => 1,
        'is_active' => true,
    ]);
}

function wfd2027Achieve(EventConfiguration $config, BonusType $objective): EventBonus
{
    return EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $objective->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => true,
        'verified_at' => now(),
    ]);
}

/**
 * Seed the published 2027 objectives through the seeder, as a fresh install does.
 */
function wfd2027SeedObjectives(): void
{
    EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    test()->seed(BonusTypeSeeder::class);
}

function wfd2027SeededObjective(EventType $wfd, string $code): BonusType
{
    return BonusType::where('event_type_id', $wfd->id)
        ->where('rules_version', '2027')
        ->where('code', $code)
        ->firstOrFail();
}

test('identifiers', function () {
    expect($this->rules->id())->toBe('WFD-2027')
        ->and($this->rules->version())->toBe('2027')
        ->and($this->rules->eventTypeCode())->toBe('WFD');
});

test('is a standalone WFD ruleset, not a Field Day one or a 2026 subclass', function () {
    expect($this->rules)->toBeInstanceOf(RuleSet::class)
        ->and($this->rules)->toBeInstanceOf(ImpliesObjectives::class)
        ->and($this->rules)->not->toBeInstanceOf(FieldDayRuleSet::class)
        ->and($this->rules)->not->toBeInstanceOf(WinterFieldDay2026::class);
});

test('the factory resolves a WFD 2027 event to this ruleset', function () {
    $config = wfd2027Config($this->wfd);

    $resolved = app(RuleSetFactory::class)->forEvent($config->event);

    expect($resolved)->toBeInstanceOf(WinterFieldDay2027::class)
        ->and(app(RuleSetFactory::class)->resolve('WFD', '2027'))->toBeInstanceOf(WinterFieldDay2027::class)
        ->and(app(RuleSetFactory::class)->resolve('WFD', '2026'))->toBeInstanceOf(WinterFieldDay2026::class);
});

// ---------------------------------------------------------------------------
// QSO points: Phone 1, CW 2, Digital 2
// ---------------------------------------------------------------------------

test('pointsForContact: Phone is 1 point, CW and Digital are 2', function () {
    $station = Station::factory()->make(['is_gota' => false]);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone', 'points_wfd' => 1, 'points_fd' => 9]);
    $cw = Mode::factory()->create(['name' => 'CW', 'category' => 'CW', 'points_wfd' => 2]);
    $digital = Mode::factory()->create(['name' => 'Digital', 'category' => 'Digital', 'points_wfd' => 2]);

    expect($this->rules->pointsForContact($phone, $station))->toBe(1)
        ->and($this->rules->pointsForContact($cw, $station))->toBe(2)
        ->and($this->rules->pointsForContact($digital, $station))->toBe(2);
});

test('pointsForContact honours only a 2027 mode_rule_points override', function () {
    $mode = Mode::factory()->create(['points_wfd' => 2]);
    $station = Station::factory()->make(['is_gota' => false]);

    ModeRulePoint::create([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2026',
        'mode_id' => $mode->id,
        'points' => 7,
    ]);

    expect($this->rules->pointsForContact($mode, $station))->toBe(2);

    ModeRulePoint::create([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2027',
        'mode_id' => $mode->id,
        'points' => 5,
    ]);

    expect($this->rules->pointsForContact($mode, $station))->toBe(5);
});

test('qsoPoints excludes duplicates and satellite contacts', function () {
    $config = wfd2027Config($this->wfd);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create(['points_wfd' => 2]);

    $attributes = [
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
    ];

    Contact::factory()->count(3)->create([...$attributes, 'is_duplicate' => false, 'is_satellite' => false]);
    Contact::factory()->create([...$attributes, 'is_duplicate' => true, 'is_satellite' => false]);
    Contact::factory()->create([...$attributes, 'is_duplicate' => false, 'is_satellite' => true]);

    expect($this->rules->qsoPoints($config))->toBe(6);
});

// ---------------------------------------------------------------------------
// Score = QSO points x (OM + 1)
// ---------------------------------------------------------------------------

test('objective multipliers accumulate and drive the score', function () {
    $config = wfd2027Config($this->wfd);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create(['points_wfd' => 2]);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 2,
        'is_duplicate' => false,
        'is_satellite' => false,
    ]);

    // Cross-band repeater (x3) + SSTV image (x2) + QRP (x4) = OM 9, so x10.
    wfd2027Achieve($config, wfd2027Objective($this->wfd, 'crossband_repeater', 3));
    wfd2027Achieve($config, wfd2027Objective($this->wfd, 'sstv_image', 2));
    wfd2027Achieve($config, wfd2027Objective($this->wfd, 'qrp', 4));

    $breakdown = $this->rules->score($config);

    expect($breakdown->value('qso_points'))->toBe(10)
        ->and($breakdown->value('objective_multiplier'))->toBe(9)
        ->and($breakdown->value('total_multiplier'))->toBe(10)
        ->and($breakdown->total)->toBe(100)
        ->and($breakdown->formula)->toBe('QSO Points x (OM + 1)');
});

test('score lines no longer carry the satellite note', function () {
    $config = wfd2027Config($this->wfd);

    expect($this->rules->score($config)->line('qso_points')->detail)->toBeNull();
});

test('unverified claims and other versions do not count', function () {
    $config = wfd2027Config($this->wfd);

    EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => wfd2027Objective($this->wfd, 'winlink_email', 1)->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => false,
    ]);

    $objective2026 = BonusType::create([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2026',
        'code' => 'fm_satellite',
        'name' => 'fm_satellite',
        'trigger_type' => 'manual',
        'base_points' => 0,
        'objective_multiplier' => 2,
        'max_occurrences' => 1,
        'is_active' => true,
    ]);
    wfd2027Achieve($config, $objective2026);

    expect($this->rules->objectiveMultiplier($config))->toBe(0);
});

// ---------------------------------------------------------------------------
// 100% alternative power qualifies for the station-equipment objective
// ---------------------------------------------------------------------------

test('claiming only 100% alternative power earns both power objectives: OM 3', function () {
    wfd2027SeedObjectives();
    $config = wfd2027Config($this->wfd);

    wfd2027Achieve($config, wfd2027SeededObjective($this->wfd, 'alt_power_full'));

    expect($this->rules->objectiveMultiplier($config))->toBe(3)
        ->and($this->rules->objectiveProgress($config))->toBe(['achieved' => 2, 'available' => 13]);
});

test('claiming both power objectives is never double-counted: OM 3', function () {
    wfd2027SeedObjectives();
    $config = wfd2027Config($this->wfd);

    wfd2027Achieve($config, wfd2027SeededObjective($this->wfd, 'alt_power_full'));
    wfd2027Achieve($config, wfd2027SeededObjective($this->wfd, 'alt_power_equipment'));

    expect($this->rules->objectiveMultiplier($config))->toBe(3)
        ->and($this->rules->objectiveProgress($config))->toBe(['achieved' => 2, 'available' => 13]);
});

test('claiming only station equipment on alternative power is OM 1', function () {
    wfd2027SeedObjectives();
    $config = wfd2027Config($this->wfd);

    wfd2027Achieve($config, wfd2027SeededObjective($this->wfd, 'alt_power_equipment'));

    expect($this->rules->objectiveMultiplier($config))->toBe(1)
        ->and($this->rules->objectiveProgress($config))->toBe(['achieved' => 1, 'available' => 13]);
});

test('an unverified 100% claim implies nothing', function () {
    wfd2027SeedObjectives();
    $config = wfd2027Config($this->wfd);

    EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => wfd2027SeededObjective($this->wfd, 'alt_power_full')->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => false,
    ]);

    expect($this->rules->objectiveMultiplier($config))->toBe(0)
        ->and($this->rules->objectiveProgress($config)['achieved'])->toBe(0);
});

test('the implied objective drives the score through the full formula', function () {
    wfd2027SeedObjectives();
    $config = wfd2027Config($this->wfd);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create(['points_wfd' => 1]);

    Contact::factory()->count(10)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 1,
        'is_duplicate' => false,
        'is_satellite' => false,
    ]);

    wfd2027Achieve($config, wfd2027SeededObjective($this->wfd, 'alt_power_full'));

    // OM 3 (full x2 + implied equipment x1), so 10 x (3 + 1).
    expect($this->rules->score($config)->total)->toBe(40);
});

test('the implication map is exposed for the scoring UI', function () {
    expect($this->rules->impliedObjectives())->toBe(['alt_power_full' => ['alt_power_equipment']]);
});

// ---------------------------------------------------------------------------
// Published objectives, straight from the 2027 rules
// ---------------------------------------------------------------------------

test('seeded objectives match the published WFDA 2027 multipliers', function () {
    wfd2027SeedObjectives();

    $expected = [
        'alt_power_equipment' => 1,
        'alt_power_full' => 2,
        'away_from_home' => 3,
        'multiple_antennas' => 1,
        'sstv_image' => 2,
        'crossband_repeater' => 3,
        'winlink_email' => 1,
        'bulletin' => 1,
        'six_bands' => 6,
        'twelve_bands' => 6,
        'multiple_modes' => 2,
        'qrp' => 4,
        'six_continuous_hours' => 2,
    ];

    $actual = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2027')
        ->whereNotNull('objective_multiplier')
        ->pluck('objective_multiplier', 'code')
        ->map(fn ($m) => (int) $m)
        ->all();

    ksort($actual);
    ksort($expected);

    expect($actual)->toBe($expected)
        // 13 objectives totalling OM 34, so completing every one scores 35x.
        ->and(array_sum($expected))->toBe(34);
});

test('a station completing every objective scores 35x its QSO points', function () {
    wfd2027SeedObjectives();
    $config = wfd2027Config($this->wfd);

    BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2027')
        ->get()
        ->each(fn (BonusType $objective) => wfd2027Achieve($config, $objective));

    expect($this->rules->objectiveMultiplier($config))->toBe(34)
        ->and($this->rules->objectiveProgress($config))->toBe(['achieved' => 13, 'available' => 13]);
});

test('satellite objectives are gone in 2027', function () {
    wfd2027SeedObjectives();

    $codes = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2027')
        ->pluck('code');

    expect($codes)->not->toContain('fm_satellite')
        ->and($codes)->not->toContain('ssb_cw_satellite')
        ->and($this->rules->strategies())->not->toHaveKey('fm_satellite')
        ->and($this->rules->bonusRuleReference('ssb_cw_satellite'))->toBeNull();
});

test('proof is required for the SSTV, repeater, Winlink and bulletin objectives', function () {
    wfd2027SeedObjectives();

    $requiringProof = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2027')
        ->where('requires_proof', true)
        ->pluck('code')
        ->sort()
        ->values()
        ->all();

    expect($requiringProof)->toBe(['bulletin', 'crossband_repeater', 'sstv_image', 'winlink_email']);
});

test('every seeded objective has a strategy with a matching trigger type', function () {
    wfd2027SeedObjectives();

    $objectives = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2027')
        ->get()
        ->keyBy('code');

    expect(array_keys($this->rules->strategies()))->toEqualCanonicalizing($objectives->keys()->all());

    foreach ($this->rules->strategies() as $code => $class) {
        $strategy = app($class);

        expect($strategy->code())->toBe($code)
            ->and($strategy->triggerType())->toBe($objectives[$code]->trigger_type, "trigger_type mismatch for {$code}");
    }
});

test('the QsoLogged index lists exactly the strategies that subscribe to it', function () {
    $subscribers = collect($this->rules->strategies())
        ->filter(fn (string $class) => in_array(QsoLogged::class, app($class)->subscribesTo(), true))
        ->values()
        ->all();

    expect($this->rules->strategiesFor(QsoLogged::class))->toEqualCanonicalizing($subscribers)
        ->and($this->rules->strategiesFor(stdClass::class))->toBe([]);
});

test('every objective exposes its verbatim 2027 rule text', function () {
    foreach (array_keys($this->rules->strategies()) as $code) {
        $reference = $this->rules->bonusRuleReference($code);

        expect($reference)->not->toBeNull("missing rule reference for {$code}")
            ->and($reference['section'])->toBe('Objective & Multipliers')
            ->and($reference['text'])->toMatch('/OM ?x\d$/');
    }

    expect($this->rules->bonusRuleReference('alt_power_full')['text'])
        ->toContain('qualifies you for the “Operate station equipment on alternative power” objective')
        ->and($this->rules->bonusRuleReference('emergency_power'))->toBeNull();
});

test('nomenclature uses WFD objective wording', function () {
    $terms = $this->rules->nomenclature();

    expect($terms->awardPlural)->toBe('Objectives')
        ->and($terms->awardsAreMultipliers)->toBeTrue()
        ->and($terms->rulebookName)->toBe('WFDA')
        ->and($terms->bulletinName)->toBe('WFD Bulletin')
        ->and($terms->bulletinSources)->toBe([])
        ->and($this->rules->cabrilloContestName())->toBe('WFD');
});

test('a deactivated objective stops counting, including when implied', function () {
    $config = wfd2027Config($this->wfd);

    $full = wfd2027Objective($this->wfd, 'alt_power_full', 2);
    $equipment = wfd2027Objective($this->wfd, 'alt_power_equipment', 1);

    wfd2027Achieve($config, $full);

    expect($this->rules->objectiveMultiplier($config))->toBe(3)
        ->and($this->rules->objectiveProgress($config))->toBe(['achieved' => 2, 'available' => 2]);

    $equipment->update(['is_active' => false]);

    expect($this->rules->objectiveMultiplier($config))->toBe(2)
        ->and($this->rules->objectiveProgress($config))->toBe(['achieved' => 1, 'available' => 1]);
});
