<?php

use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Scoring\Bonuses\WinterFieldDay2027\MultipleModesStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\QrpStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\SixBandsStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\TwelveBandsStrategy;
use App\Scoring\Rules\WinterFieldDay2027;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'scoring');

beforeEach(function () {
    $this->wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);

    $event = Event::factory()->create([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2027',
    ]);

    $this->config = EventConfiguration::factory()->create(['event_id' => $event->id]);
});

function wfd2027ObjectiveRow(EventType $wfd, string $code, int $multiplier): BonusType
{
    return BonusType::create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2027',
        'code' => $code,
        'name' => $code,
        'trigger_type' => 'derived',
        'base_points' => 0,
        'objective_multiplier' => $multiplier,
        'max_occurrences' => 1,
        'is_active' => true,
    ]);
}

/**
 * Log $count non-duplicate contacts on a freshly created band.
 *
 * @param  array<string, mixed>  $bandAttributes
 * @param  array<string, mixed>  $contactAttributes
 */
function wfd2027ContactsOnNewBand(
    EventConfiguration $config,
    Mode $mode,
    int $count,
    string $name,
    array $bandAttributes = [],
    array $contactAttributes = [],
): Band {
    $band = Band::factory()->create(['name' => $name, 'is_satellite' => false, ...$bandAttributes]);

    Contact::factory()->count($count)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 1,
        'is_duplicate' => false,
        'is_satellite' => false,
        'power_watts' => null,
        ...$contactAttributes,
    ]);

    return $band;
}

function wfd2027Achieved(EventConfiguration $config, BonusType $objective): bool
{
    return EventBonus::where('event_configuration_id', $config->id)
        ->where('bonus_type_id', $objective->id)
        ->where('is_verified', true)
        ->exists();
}

// ---------------------------------------------------------------------------
// Band-count objectives
// ---------------------------------------------------------------------------

test('six bands objective needs three contacts on each of six bands', function () {
    $objective = wfd2027ObjectiveRow($this->wfd, 'six_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach (['160m', '80m', '40m', '20m', '15m'] as $name) {
        wfd2027ContactsOnNewBand($this->config, $mode, 3, $name);
    }
    $sixth = wfd2027ContactsOnNewBand($this->config, $mode, 2, '10m');

    (new SixBandsStrategy)->reconcile($this->config);
    expect(wfd2027Achieved($this->config, $objective))->toBeFalse();

    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $sixth->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_satellite' => false,
    ]);

    (new SixBandsStrategy)->reconcile($this->config);
    expect(wfd2027Achieved($this->config, $objective))->toBeTrue();
});

test('the Satellite pseudo-band does not count as a band', function () {
    $objective = wfd2027ObjectiveRow($this->wfd, 'six_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach (['160m', '80m', '40m', '20m', '15m'] as $name) {
        wfd2027ContactsOnNewBand($this->config, $mode, 3, $name);
    }
    wfd2027ContactsOnNewBand($this->config, $mode, 3, 'Satellite', ['is_satellite' => true]);

    (new SixBandsStrategy)->reconcile($this->config);

    expect(wfd2027Achieved($this->config, $objective))->toBeFalse();
});

test('satellite contacts on a real band do not count toward that band', function () {
    $objective = wfd2027ObjectiveRow($this->wfd, 'six_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach (['160m', '80m', '40m', '20m', '15m'] as $name) {
        wfd2027ContactsOnNewBand($this->config, $mode, 3, $name);
    }
    $band = wfd2027ContactsOnNewBand($this->config, $mode, 2, '70cm');
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
        'is_satellite' => true,
    ]);

    (new SixBandsStrategy)->reconcile($this->config);

    expect(wfd2027Achieved($this->config, $objective))->toBeFalse();
});

test('band tally ignores duplicates', function () {
    $objective = wfd2027ObjectiveRow($this->wfd, 'six_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach (['160m', '80m', '40m', '20m', '15m'] as $name) {
        wfd2027ContactsOnNewBand($this->config, $mode, 3, $name);
    }
    $band = wfd2027ContactsOnNewBand($this->config, $mode, 2, '10m');
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => true,
        'is_satellite' => false,
    ]);

    (new SixBandsStrategy)->reconcile($this->config);

    expect(wfd2027Achieved($this->config, $objective))->toBeFalse();
});

test('twelve bands objective requires twelve qualifying non-satellite bands', function () {
    $six = wfd2027ObjectiveRow($this->wfd, 'six_bands', 6);
    $twelve = wfd2027ObjectiveRow($this->wfd, 'twelve_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach (range(1, 11) as $i) {
        wfd2027ContactsOnNewBand($this->config, $mode, 3, "band-{$i}");
    }
    wfd2027ContactsOnNewBand($this->config, $mode, 3, 'Satellite', ['is_satellite' => true]);

    (new SixBandsStrategy)->reconcile($this->config);
    (new TwelveBandsStrategy)->reconcile($this->config);

    expect(wfd2027Achieved($this->config, $six))->toBeTrue()
        ->and(wfd2027Achieved($this->config, $twelve))->toBeFalse();

    wfd2027ContactsOnNewBand($this->config, $mode, 3, 'band-12');
    (new TwelveBandsStrategy)->reconcile($this->config);

    expect(wfd2027Achieved($this->config, $twelve))->toBeTrue();
});

test('reconcile is idempotent and revokes an objective no longer met', function () {
    $objective = wfd2027ObjectiveRow($this->wfd, 'six_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach (['160m', '80m', '40m', '20m', '15m', '10m'] as $name) {
        wfd2027ContactsOnNewBand($this->config, $mode, 3, $name);
    }

    (new SixBandsStrategy)->reconcile($this->config);
    (new SixBandsStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(1);

    Contact::where('event_configuration_id', $this->config->id)->delete();
    (new SixBandsStrategy)->reconcile($this->config);

    expect(wfd2027Achieved($this->config, $objective))->toBeFalse();
});

test('band strategies do nothing for a 2026 objective row', function () {
    $mode = Mode::factory()->create(['category' => 'Phone']);
    BonusType::create([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2026',
        'code' => 'six_bands',
        'name' => 'six_bands',
        'trigger_type' => 'derived',
        'base_points' => 0,
        'objective_multiplier' => 6,
        'max_occurrences' => 1,
        'is_active' => true,
    ]);

    foreach (['160m', '80m', '40m', '20m', '15m', '10m'] as $name) {
        wfd2027ContactsOnNewBand($this->config, $mode, 3, $name);
    }

    (new SixBandsStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Logic carried forward from 2026
// ---------------------------------------------------------------------------

test('multiple modes needs two mode groups, and a third adds nothing', function () {
    $objective = wfd2027ObjectiveRow($this->wfd, 'multiple_modes', 2);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    $cw = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);
    $digital = Mode::factory()->create(['name' => 'Digital', 'category' => 'Digital']);

    wfd2027ContactsOnNewBand($this->config, $phone, 1, '20m');
    (new MultipleModesStrategy)->reconcile($this->config);
    expect(wfd2027Achieved($this->config, $objective))->toBeFalse();

    wfd2027ContactsOnNewBand($this->config, $cw, 1, '40m');
    (new MultipleModesStrategy)->reconcile($this->config);
    expect(wfd2027Achieved($this->config, $objective))->toBeTrue();

    wfd2027ContactsOnNewBand($this->config, $digital, 1, '15m');
    (new MultipleModesStrategy)->reconcile($this->config);

    expect((new WinterFieldDay2027)->objectiveMultiplier($this->config))->toBe(2);
});

test('QRP holds CW to 5W and Phone to 10W', function () {
    $objective = wfd2027ObjectiveRow($this->wfd, 'qrp', 4);
    $this->config->update(['max_power_watts' => 10]);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    $cw = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);

    wfd2027ContactsOnNewBand($this->config, $phone, 2, '20m', contactAttributes: ['power_watts' => 10]);
    wfd2027ContactsOnNewBand($this->config, $cw, 2, '40m', contactAttributes: ['power_watts' => 5]);

    (new QrpStrategy)->reconcile($this->config->fresh());
    expect(wfd2027Achieved($this->config, $objective))->toBeTrue();

    wfd2027ContactsOnNewBand($this->config, $cw, 1, '80m', contactAttributes: ['power_watts' => 6]);
    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(wfd2027Achieved($this->config, $objective))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Strategy wiring
// ---------------------------------------------------------------------------

test('every objective code maps to a strategy', function () {
    $strategies = (new WinterFieldDay2027)->strategies();

    expect(array_keys($strategies))->toHaveCount(13);

    foreach ($strategies as $code => $class) {
        expect(app($class)->code())->toBe($code)
            ->and($class)->toStartWith('App\\Scoring\\Bonuses\\WinterFieldDay2027\\');
    }
});

test('both alternative power objectives are claimed by hand', function () {
    $rules = new WinterFieldDay2027;

    foreach (['alt_power_equipment', 'alt_power_full', 'sstv_image', 'crossband_repeater'] as $code) {
        $strategy = app($rules->strategies()[$code]);

        expect($strategy->triggerType())->toBe('manual')
            ->and($strategy->subscribesTo())->toBe([]);
    }
});
