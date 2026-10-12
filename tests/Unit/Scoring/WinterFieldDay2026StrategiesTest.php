<?php

use App\Enums\PowerSource;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\Station;
use App\Scoring\Bonuses\WinterFieldDay2026\AlternativePowerStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\MultipleModesStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\QrpStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\SixBandsStrategy;
use App\Scoring\Bonuses\WinterFieldDay2026\TwelveBandsStrategy;
use App\Scoring\Rules\WinterFieldDay2026;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'scoring');

beforeEach(function () {
    $this->wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);

    $event = Event::factory()->create([
        'event_type_id' => $this->wfd->id,
        'rules_version' => '2026',
    ]);

    $this->config = EventConfiguration::factory()->create(['event_id' => $event->id]);
});

function objectiveRow(EventType $wfd, string $code, int $multiplier): BonusType
{
    return BonusType::create([
        'event_type_id' => $wfd->id,
        'rules_version' => '2026',
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
 */
function contactsOnNewBand(EventConfiguration $config, Mode $mode, int $count, int $meters, ?int $powerWatts = null): Band
{
    $band = Band::factory()->create(['name' => "{$meters}m", 'meters' => $meters]);

    Contact::factory()->count($count)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => 1,
        'is_duplicate' => false,
        // The factory randomises power; QRP is judged per contact, so tests
        // that care about power must pin it rather than inherit a random value.
        'power_watts' => $powerWatts,
    ]);

    return $band;
}

function achieved(EventConfiguration $config, BonusType $objective): bool
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
    $objective = objectiveRow($this->wfd, 'six_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    // Five qualifying bands plus a sixth band one contact short.
    foreach ([160, 80, 40, 20, 15] as $meters) {
        contactsOnNewBand($this->config, $mode, 3, $meters);
    }
    $sixthBand = contactsOnNewBand($this->config, $mode, 2, 10);

    (new SixBandsStrategy)->reconcile($this->config);
    expect(achieved($this->config, $objective))->toBeFalse();

    // A third contact on the sixth band crosses the per-band threshold.
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $sixthBand->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
    ]);

    (new SixBandsStrategy)->reconcile($this->config);
    expect(achieved($this->config, $objective))->toBeTrue();
});

test('band tally ignores duplicates', function () {
    $objective = objectiveRow($this->wfd, 'six_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach ([160, 80, 40, 20, 15] as $meters) {
        contactsOnNewBand($this->config, $mode, 3, $meters);
    }

    $band = Band::factory()->create(['name' => '10m', 'meters' => 10]);
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => false,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'is_duplicate' => true,
    ]);

    (new SixBandsStrategy)->reconcile($this->config);

    expect(achieved($this->config, $objective))->toBeFalse();
});

test('twelve bands objective requires twelve qualifying bands', function () {
    $six = objectiveRow($this->wfd, 'six_bands', 6);
    $twelve = objectiveRow($this->wfd, 'twelve_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach (range(1, 12) as $i) {
        contactsOnNewBand($this->config, $mode, 3, $i);
    }

    (new SixBandsStrategy)->reconcile($this->config);
    (new TwelveBandsStrategy)->reconcile($this->config);

    // The SOP says the six bands count toward the twelve, so both are held.
    expect(achieved($this->config, $six))->toBeTrue()
        ->and(achieved($this->config, $twelve))->toBeTrue();
});

test('reconcile is idempotent and revokes an objective no longer met', function () {
    $objective = objectiveRow($this->wfd, 'six_bands', 6);
    $mode = Mode::factory()->create(['category' => 'Phone']);

    foreach ([160, 80, 40, 20, 15, 10] as $meters) {
        contactsOnNewBand($this->config, $mode, 3, $meters);
    }

    (new SixBandsStrategy)->reconcile($this->config);
    (new SixBandsStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(1);

    Contact::where('event_configuration_id', $this->config->id)->delete();
    (new SixBandsStrategy)->reconcile($this->config);

    expect(achieved($this->config, $objective))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Multiple modes
// ---------------------------------------------------------------------------

test('multiple modes needs two mode groups, and a third adds nothing', function () {
    $objective = objectiveRow($this->wfd, 'multiple_modes', 2);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    $cw = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);
    $digital = Mode::factory()->create(['name' => 'Digital', 'category' => 'Digital']);

    contactsOnNewBand($this->config, $phone, 1, 20);
    (new MultipleModesStrategy)->reconcile($this->config);
    expect(achieved($this->config, $objective))->toBeFalse();

    contactsOnNewBand($this->config, $cw, 1, 40);
    (new MultipleModesStrategy)->reconcile($this->config);
    expect(achieved($this->config, $objective))->toBeTrue();

    contactsOnNewBand($this->config, $digital, 1, 15);
    (new MultipleModesStrategy)->reconcile($this->config);

    $bonus = EventBonus::where('event_configuration_id', $this->config->id)
        ->where('bonus_type_id', $objective->id)
        ->first();

    expect($bonus->quantity)->toBe(1)
        ->and((new WinterFieldDay2026)->objectiveMultiplier($this->config))->toBe(2);
});

// ---------------------------------------------------------------------------
// QRP
// ---------------------------------------------------------------------------

test('QRP allows 10W on a Phone-only operation', function () {
    $objective = objectiveRow($this->wfd, 'qrp', 4);
    $this->config->update(['max_power_watts' => 10]);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    contactsOnNewBand($this->config, $phone, 1, 20, 10);

    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeTrue();
});

test('QRP holds CW and Digital contacts to the 5W ceiling', function () {
    $objective = objectiveRow($this->wfd, 'qrp', 4);
    $this->config->update(['max_power_watts' => 10]);

    $cw = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);
    contactsOnNewBand($this->config, $cw, 1, 40, 10);

    (new QrpStrategy)->reconcile($this->config->fresh());
    expect(achieved($this->config, $objective))->toBeFalse();

    Contact::where('event_configuration_id', $this->config->id)->update(['power_watts' => 5]);
    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeTrue();
});

test('QRP is awarded for 10W Phone alongside 5W CW, each within its own ceiling', function () {
    $objective = objectiveRow($this->wfd, 'qrp', 4);
    // The operation's configured maximum is the Phone figure; the CW contacts
    // ran lower. Judging every contact against 5W would deny a valid claim.
    $this->config->update(['max_power_watts' => 10]);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    $cw = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);

    contactsOnNewBand($this->config, $phone, 2, 20, 10);
    contactsOnNewBand($this->config, $cw, 2, 40, 5);

    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeTrue();
});

test('QRP is denied when a single CW contact exceeds 5W despite legal Phone power', function () {
    $objective = objectiveRow($this->wfd, 'qrp', 4);
    $this->config->update(['max_power_watts' => 10]);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    $cw = Mode::factory()->create(['name' => 'CW', 'category' => 'CW']);

    contactsOnNewBand($this->config, $phone, 2, 20, 10);
    contactsOnNewBand($this->config, $cw, 1, 40, 6);

    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeFalse();
});

test('QRP holds Digital to the CW ceiling, not the Phone one', function () {
    $objective = objectiveRow($this->wfd, 'qrp', 4);
    $this->config->update(['max_power_watts' => 10]);

    $digital = Mode::factory()->create(['name' => 'FT8', 'category' => 'Digital']);
    contactsOnNewBand($this->config, $digital, 1, 20, 10);

    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeFalse();
});

test('QRP falls back to configured power for contacts with no recorded power', function () {
    $objective = objectiveRow($this->wfd, 'qrp', 4);
    $this->config->update(['max_power_watts' => 10]);

    $phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone']);
    contactsOnNewBand($this->config, $phone, 1, 20, null);

    (new QrpStrategy)->reconcile($this->config->fresh());
    expect(achieved($this->config, $objective))->toBeTrue();

    // Same contacts, but the operation was configured above the Phone ceiling.
    $this->config->update(['max_power_watts' => 50]);
    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeFalse();
});

test('QRP is not awarded to an operation running 100W', function () {
    $objective = objectiveRow($this->wfd, 'qrp', 4);
    $this->config->update(['max_power_watts' => 100]);

    $phone = Mode::factory()->create(['category' => 'Phone']);
    contactsOnNewBand($this->config, $phone, 1, 20, 100);

    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeFalse();
});

test('QRP respects a station running higher power than the event config', function () {
    $objective = objectiveRow($this->wfd, 'qrp', 4);
    $this->config->update(['max_power_watts' => 5]);

    Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'max_power_watts' => 50,
    ]);

    $phone = Mode::factory()->create(['category' => 'Phone']);
    contactsOnNewBand($this->config, $phone, 1, 20, null);

    (new QrpStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Alternative power
// ---------------------------------------------------------------------------

test('alternative power is awarded when nothing runs on commercial mains', function () {
    $objective = objectiveRow($this->wfd, 'alternative_power', 1);
    $this->config->update(['uses_commercial_power' => false]);

    (new AlternativePowerStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeTrue();
});

test('alternative power is denied when the event runs on commercial power', function () {
    $objective = objectiveRow($this->wfd, 'alternative_power', 1);
    $this->config->update(['uses_commercial_power' => true]);

    (new AlternativePowerStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeFalse();
});

test('alternative power is denied when any station is on commercial mains', function () {
    $objective = objectiveRow($this->wfd, 'alternative_power', 1);
    $this->config->update(['uses_commercial_power' => false]);

    Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'power_source' => PowerSource::CommercialMains->value,
    ]);

    (new AlternativePowerStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeFalse();
});

test('a generator still counts as alternative power under WFD rules', function () {
    $objective = objectiveRow($this->wfd, 'alternative_power', 1);
    $this->config->update([
        'uses_commercial_power' => false,
        'uses_generator' => true,
    ]);

    (new AlternativePowerStrategy)->reconcile($this->config->fresh());

    expect(achieved($this->config, $objective))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Strategy wiring
// ---------------------------------------------------------------------------

test('every objective code maps to a strategy', function () {
    $rules = new WinterFieldDay2026;
    $strategies = $rules->strategies();

    expect(array_keys($strategies))->toHaveCount(12);

    foreach ($strategies as $code => $class) {
        expect(app($class)->code())->toBe($code);
    }
});

test('satellite objectives are manual because modes are stored by category', function () {
    $rules = new WinterFieldDay2026;

    foreach (['fm_satellite', 'ssb_cw_satellite'] as $code) {
        expect(app($rules->strategies()[$code])->triggerType())->toBe('manual');
    }
});
