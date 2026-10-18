<?php

use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Scoring\Bonuses\WinterFieldDay2027\MultipleModesStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\SixBandsStrategy;
use App\Scoring\Contracts\DeterminesContactCredit;
use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\WinterFieldDay2026;
use App\Scoring\Rules\WinterFieldDay2027;
use App\Services\RescoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'scoring');

/**
 * WFDA "Winter Field Day 2027" rules, Version 3, page 5: "All modes, except
 * WSJT modes, may be used" and "You may only claim credit for contacts that
 * are on one band, one mode, simplex, and made directly to the receiving
 * station." Such contacts are accepted but earn no credit.
 */
beforeEach(function () {
    $this->wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
    $this->rules = new WinterFieldDay2027;
    $this->hfBand = Band::factory()->create(['name' => '20m', 'is_satellite' => false]);
    $this->satBand = Band::factory()->create(['name' => 'Satellite', 'is_satellite' => true]);
    $this->digital = Mode::factory()->create(['name' => 'Digital', 'category' => 'Digital', 'points_wfd' => 2, 'points_fd' => 2]);
    $this->phone = Mode::factory()->create(['name' => 'Phone', 'category' => 'Phone', 'points_wfd' => 1, 'points_fd' => 1]);
});

function creditConfig(EventType $type, string $version): EventConfiguration
{
    $event = Event::factory()->create([
        'event_type_id' => $type->id,
        'rules_version' => $version,
    ]);

    return EventConfiguration::factory()->create(['event_id' => $event->id]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function creditContact(EventConfiguration $config, Band $band, Mode $mode, array $attributes = []): Contact
{
    return Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'points' => (int) $mode->points_wfd,
        'is_duplicate' => false,
        'is_satellite' => false,
        'submode' => null,
        ...$attributes,
    ]);
}

// ---------------------------------------------------------------------------
// The rules, contact by contact
// ---------------------------------------------------------------------------

test('every WSJT mode, and its variants, earns no credit', function (string $submode) {
    $contact = new Contact(['submode' => $submode, 'is_satellite' => false]);

    expect($this->rules->noCreditReason($contact))->toBe(WinterFieldDay2027::NO_CREDIT_WSJT);
})->with([
    'FT2', 'FST4', 'FT4', 'FT8', 'JT4', 'JT9', 'JT65', 'Q65', 'MSK144', 'WSPR', 'FST4W', 'ECHO',
    'JT9-1', 'JT65A', 'JT4G', 'Q65-30A', 'FST4-60', 'ft8',
]);

test('other modes earn credit', function (?string $submode) {
    $contact = new Contact(['submode' => $submode, 'is_satellite' => false]);

    expect($this->rules->noCreditReason($contact))->toBeNull();
})->with(['RTTY', 'PSK31', 'JS8', 'JTTY', 'SSTV', 'OLIVIA', 'USB', 'FM', 'CW', null]);

test('a satellite contact earns no credit', function () {
    $contact = new Contact(['submode' => 'FM', 'is_satellite' => true, 'band_id' => $this->hfBand->id]);

    expect($this->rules->noCreditReason($contact))->toBe(WinterFieldDay2027::NO_CREDIT_SATELLITE);
});

test('a contact on the Satellite pseudo-band earns no credit', function () {
    $contact = new Contact(['submode' => 'FM', 'is_satellite' => false, 'band_id' => $this->satBand->id]);

    expect($this->rules->noCreditReason($contact))->toBe(WinterFieldDay2027::NO_CREDIT_SATELLITE);
});

test('the SQL rules agree with the per-contact rules', function () {
    $config = creditConfig($this->wfd, '2027');

    foreach ([null, 'FT8', 'JT65A', 'Q65-30A', 'RTTY', 'JS8', 'JTTY', 'USB', 'MSK144', 'ECHO', 'FST4W'] as $submode) {
        foreach ([$this->hfBand, $this->satBand] as $band) {
            foreach ([false, true] as $isSatellite) {
                creditContact($config, $band, $this->digital, ['submode' => $submode, 'is_satellite' => $isSatellite]);
            }
        }
    }
    creditContact($config, $this->hfBand, $this->digital, ['band_id' => null]);
    creditContact($config, $this->hfBand, $this->digital, ['band_id' => null, 'submode' => 'FT4']);

    $all = $config->contacts()->with('band')->get();
    $creditableInPhp = $all->filter(fn (Contact $contact) => $this->rules->noCreditReason($contact) === null)
        ->pluck('id')->sort()->values()->all();

    $query = Contact::query()->where('event_configuration_id', $config->id);
    $this->rules->constrainToCreditable($query);

    expect($query->pluck('id')->sort()->values()->all())->toBe($creditableInPhp)
        ->and($creditableInPhp)->toHaveCount(6)
        ->and($config->creditableContacts()->pluck('id')->sort()->values()->all())->toBe($creditableInPhp);
});

// ---------------------------------------------------------------------------
// The EventConfiguration entry point
// ---------------------------------------------------------------------------

test('the configuration resolves the credit rules from its ruleset', function () {
    $config = creditConfig($this->wfd, '2027');
    $ft8 = creditContact($config, $this->hfBand, $this->digital, ['submode' => 'FT8']);
    $rtty = creditContact($config, $this->hfBand, $this->digital, ['submode' => 'RTTY']);
    creditContact($config, $this->hfBand, $this->digital, ['submode' => 'RTTY', 'is_duplicate' => true]);

    expect($config->contactCreditRules())->toBeInstanceOf(DeterminesContactCredit::class)
        ->and($config->contactNoCreditReason($ft8))->toBe(WinterFieldDay2027::NO_CREDIT_WSJT)
        ->and($config->contactNoCreditReason($rtty))->toBeNull()
        ->and($config->creditableContacts()->pluck('id')->all())->toBe([$rtty->id]);
});

test('rulesets without credit rules credit every non-duplicate contact', function (string $code, string $version) {
    $type = EventType::firstOrCreate(['code' => $code], ['name' => $code]);
    $config = creditConfig($type, $version);

    $ft8 = creditContact($config, $this->hfBand, $this->digital, ['submode' => 'FT8', 'points' => 2]);
    $satellite = creditContact($config, $this->satBand, $this->digital, ['is_satellite' => true, 'points' => 2]);
    creditContact($config, $this->hfBand, $this->digital, ['is_duplicate' => true, 'points' => 0]);

    expect($config->contactCreditRules())->toBeNull()
        ->and($config->contactNoCreditReason($ft8))->toBeNull()
        ->and($config->creditableContacts()->pluck('id')->sort()->values()->all())->toBe([$ft8->id, $satellite->id])
        ->and($ft8->fresh()->points)->toBe(2)
        ->and($satellite->fresh()->points)->toBe(2);
})->with([
    'Field Day 2025' => ['FD', '2025'],
    'Winter Field Day 2026' => ['WFD', '2026'],
]);

test('Field Day and WFD 2026 do not implement the credit contract', function () {
    expect(new FieldDay2025)->not->toBeInstanceOf(DeterminesContactCredit::class)
        ->and(new WinterFieldDay2026)->not->toBeInstanceOf(DeterminesContactCredit::class);
});

// ---------------------------------------------------------------------------
// Scoring
// ---------------------------------------------------------------------------

test('qsoPoints excludes WSJT-mode and satellite contacts', function () {
    $config = creditConfig($this->wfd, '2027');

    creditContact($config, $this->hfBand, $this->digital, ['submode' => 'RTTY']);
    creditContact($config, $this->hfBand, $this->phone, ['submode' => 'USB']);
    creditContact($config, $this->hfBand, $this->digital, ['submode' => 'FT8']);
    creditContact($config, $this->satBand, $this->phone, ['submode' => 'FM', 'is_satellite' => true]);

    $breakdown = $this->rules->score($config);

    expect($this->rules->qsoPoints($config))->toBe(3)
        ->and($breakdown->value('qso_points'))->toBe(3)
        ->and($breakdown->value('no_credit_contacts'))->toBe(2);
});

test('a no-credit contact is stored with zero points', function () {
    $config = creditConfig($this->wfd, '2027');

    $ft8 = creditContact($config, $this->hfBand, $this->digital, ['submode' => 'FT8', 'points' => 2]);
    $satellite = creditContact($config, $this->hfBand, $this->phone, ['is_satellite' => true, 'points' => 1]);
    $rtty = creditContact($config, $this->hfBand, $this->digital, ['submode' => 'RTTY', 'points' => 2]);

    expect($ft8->fresh()->points)->toBe(0)
        ->and($satellite->fresh()->points)->toBe(0)
        ->and($rtty->fresh()->points)->toBe(2);
});

test('editing a contact out of a WSJT mode lets it keep its points', function () {
    $config = creditConfig($this->wfd, '2027');
    $contact = creditContact($config, $this->hfBand, $this->digital, ['submode' => 'FT8', 'points' => 2]);

    $contact->update(['submode' => 'RTTY', 'points' => 2]);
    expect($contact->fresh()->points)->toBe(2);

    $contact->update(['submode' => 'FT4', 'points' => 2]);
    expect($contact->fresh()->points)->toBe(0);
});

test('rescoring zeroes no-credit contacts under 2027 and restores them under 2026', function () {
    $event = Event::factory()->create(['event_type_id' => $this->wfd->id, 'rules_version' => '2026']);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create(['event_configuration_id' => $config->id, 'is_gota' => false]);
    $session = OperatingSession::factory()->create(['station_id' => $station->id, 'mode_id' => $this->digital->id]);

    // Inside the event window: a rescore gives out-of-window contacts nothing.
    $ft8 = creditContact($config, $this->hfBand, $this->digital, [
        'submode' => 'FT8',
        'points' => 2,
        'operating_session_id' => $session->id,
        'qso_time' => $event->start_time->copy()->addHour(),
    ]);

    expect($ft8->fresh()->points)->toBe(2);

    $event->update(['rules_version' => '2027']);
    app(RescoreService::class)->rescoreEvent($event->fresh());
    expect($ft8->fresh()->points)->toBe(0);

    $event->update(['rules_version' => '2026']);
    app(RescoreService::class)->rescoreEvent($event->fresh());
    expect($ft8->fresh()->points)->toBe(2);
});

// ---------------------------------------------------------------------------
// Objectives
// ---------------------------------------------------------------------------

function creditObjective(EventType $wfd, string $code, int $multiplier): BonusType
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

function creditObjectiveAchieved(EventConfiguration $config, BonusType $objective): bool
{
    return EventBonus::where('event_configuration_id', $config->id)
        ->where('bonus_type_id', $objective->id)
        ->where('is_verified', true)
        ->exists();
}

test('multiple modes ignores WSJT-mode and satellite contacts', function () {
    $config = creditConfig($this->wfd, '2027');
    $objective = creditObjective($this->wfd, 'multiple_modes', 2);

    creditContact($config, $this->hfBand, $this->phone, ['submode' => 'USB']);
    creditContact($config, $this->hfBand, $this->digital, ['submode' => 'FT8']);
    creditContact($config, $this->satBand, $this->digital, ['submode' => 'PSK31', 'is_satellite' => true]);

    (new MultipleModesStrategy)->reconcile($config);
    expect(creditObjectiveAchieved($config, $objective))->toBeFalse();

    creditContact($config, $this->hfBand, $this->digital, ['submode' => 'RTTY']);

    (new MultipleModesStrategy)->reconcile($config);
    expect(creditObjectiveAchieved($config, $objective))->toBeTrue();
});

test('WSJT-mode contacts do not make a band count', function () {
    $config = creditConfig($this->wfd, '2027');
    $objective = creditObjective($this->wfd, 'six_bands', 6);

    foreach (['160m', '80m', '40m', '15m', '10m'] as $name) {
        $band = Band::factory()->create(['name' => $name, 'is_satellite' => false]);
        foreach (range(1, 3) as $i) {
            creditContact($config, $band, $this->phone, ['submode' => 'USB']);
        }
    }
    foreach (range(1, 3) as $i) {
        creditContact($config, $this->hfBand, $this->digital, ['submode' => 'FT8']);
    }

    (new SixBandsStrategy)->reconcile($config);
    expect(creditObjectiveAchieved($config, $objective))->toBeFalse();

    foreach (range(1, 3) as $i) {
        creditContact($config, $this->hfBand, $this->digital, ['submode' => 'RTTY']);
    }

    (new SixBandsStrategy)->reconcile($config);
    expect(creditObjectiveAchieved($config, $objective))->toBeTrue();
});
