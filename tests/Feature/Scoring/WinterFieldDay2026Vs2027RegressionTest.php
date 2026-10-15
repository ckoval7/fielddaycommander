<?php

use App\Enums\PowerSource;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Scoring\EventBonusReconciler;
use App\Scoring\Rules\WinterFieldDay2026;
use App\Services\RescoreService;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('feature', 'scoring');

/**
 * The same WFD contact log and claims, scored under the frozen 2026 rules and
 * under the 2027 rules, and rescored between them.
 */
beforeEach(function () {
    $this->seed(EventTypeSeeder::class);
    $this->seed(BonusTypeSeeder::class);

    $this->wfd = EventType::where('code', 'WFD')->firstOrFail();

    $this->phone = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->cw = Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->digital = Mode::create(['name' => 'Digital', 'category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
});

/**
 * Build the shared fixture at the given rules version.
 *
 * Contacts (stored points are what a pre-2027 log holds: Phone 1, CW 2, Digital 2):
 *   Phone   20m x3, 40m x3, 15m x3   -> 9 points
 *   CW      80m x3, 10m x3           -> 12 points
 *   Digital (PSK31) 2m x3            -> 6 points
 *   Digital (FT8)   6m x1            -> 2 points under 2026, no credit in 2027
 *   Phone   Satellite band, is_satellite x1 -> 1 stored point, no credit in either year
 *   Phone   20m duplicate x1         -> 0 points
 *
 * @return array{config: EventConfiguration, station: Station}
 */
function wfdRegressionFixture(string $version): array
{
    $wfd = EventType::where('code', 'WFD')->firstOrFail();

    $class = OperatingClass::create([
        'event_type_id' => $wfd->id,
        'code' => 'O',
        'name' => 'Outdoor',
        'allows_gota' => false,
    ]);

    $event = Event::factory()->create(['event_type_id' => $wfd->id, 'rules_version' => $version]);

    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'operating_class_id' => $class->id,
        'max_power_watts' => 100,
        'uses_commercial_power' => false,
        'has_gota_station' => false,
    ]);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'is_gota' => false,
        'power_source' => PowerSource::Battery,
        'max_power_watts' => 100,
    ]);

    $session = OperatingSession::factory()->create(['station_id' => $station->id]);

    $phone = Mode::where('name', 'Phone')->first();
    $cw = Mode::where('name', 'CW')->first();
    $digital = Mode::where('name', 'Digital')->first();

    $log = function (Mode $mode, string $bandName, int $count, array $overrides = []) use ($config, $session) {
        $band = Band::where('name', $bandName)->first()
            ?? Band::factory()->create(['name' => $bandName, 'meters' => (int) $bandName ?: 1, 'is_satellite' => $bandName === 'Satellite']);

        for ($i = 0; $i < $count; $i++) {
            Contact::factory()->create([
                'event_configuration_id' => $config->id,
                'operating_session_id' => $session->id,
                'band_id' => $band->id,
                'mode_id' => $mode->id,
                'power_watts' => 100,
                'is_duplicate' => false,
                'is_satellite' => false,
                'points' => (int) $mode->points_wfd,
                ...$overrides,
            ]);
        }
    };

    $log($phone, '20m', 3);
    $log($phone, '40m', 3);
    $log($phone, '15m', 3);
    $log($cw, '80m', 3);
    $log($cw, '10m', 3);
    $log($digital, '2m', 3, ['submode' => 'PSK31']);
    $log($digital, '6m', 1, ['submode' => 'FT8']);
    $log($phone, 'Satellite', 1, ['is_satellite' => true]);
    $log($phone, '20m', 1, ['is_duplicate' => true, 'points' => 0]);

    return ['config' => $config, 'station' => $station];
}

function wfdRegressionObjective(string $version, string $code): BonusType
{
    return BonusType::where('event_type_id', EventType::where('code', 'WFD')->value('id'))
        ->where('rules_version', $version)
        ->where('code', $code)
        ->firstOrFail();
}

function wfdClaim(EventConfiguration $config, string $version, string $code): void
{
    $config->bonuses()->create([
        'bonus_type_id' => wfdRegressionObjective($version, $code)->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => true,
        'verified_at' => now(),
    ]);
}

function wfdScore(EventConfiguration $config)
{
    return EventConfiguration::find($config->id)->scoreBreakdown();
}

test('2026: only the satellite QSO is excluded; FT8 scores 2 and the objectives multiply', function () {
    ['config' => $config] = wfdRegressionFixture('2026');

    wfdClaim($config, '2026', 'away_from_home');
    wfdClaim($config, '2026', 'fm_satellite');
    app(EventBonusReconciler::class)->reconcileAll($config);

    $breakdown = wfdScore($config);

    // QSO points: 9 Phone + 12 CW + 6 PSK31 + 2 FT8 = 29. The satellite QSO
    // (1 stored point) is excluded by the SOP; the duplicate is 0.
    expect($breakdown->value('qso_points'))->toBe(29);

    // Objectives (2026): six_bands 6 (20/40/15/80/10/2m each have 3) + multiple_modes 2
    // + alternative_power 1 (derived: battery power, no mains) + away_from_home 3 (claimed)
    // + fm_satellite 2 (claimed) = 14. QRP fails (100 W), twelve_bands fails.
    expect($breakdown->value('objective_multiplier'))->toBe(14)
        ->and($breakdown->value('total_multiplier'))->toBe(15)
        ->and($breakdown->total)->toBe(29 * 15);
});

test('2027: FT8 and satellite earn nothing, alt_power_full implies equipment, fm_satellite does not exist', function () {
    ['config' => $config] = wfdRegressionFixture('2027');

    // Saving through the observer zeroes the FT8 and satellite points.
    expect(Contact::where('event_configuration_id', $config->id)->where('submode', 'FT8')->value('points'))->toBe(0)
        ->and(Contact::where('event_configuration_id', $config->id)->where('is_satellite', true)->value('points'))->toBe(0);

    expect(BonusType::where('rules_version', '2027')->where('code', 'fm_satellite')->exists())->toBeFalse();

    wfdClaim($config, '2027', 'away_from_home');
    wfdClaim($config, '2027', 'alt_power_full');
    app(EventBonusReconciler::class)->reconcileAll($config);

    $breakdown = wfdScore($config);

    // QSO points: 9 Phone + 12 CW + 6 PSK31 = 27 (FT8 and satellite zeroed).
    expect($breakdown->value('qso_points'))->toBe(27)
        // FT8 + satellite; the duplicate is not counted.
        ->and($breakdown->value('no_credit_contacts'))->toBe(2);

    // Objectives (2027): six_bands 6 + multiple_modes 2 + away_from_home 3
    // + alt_power_full 2 + implied alt_power_equipment 1 = 14.
    expect($breakdown->value('objective_multiplier'))->toBe(14)
        ->and($breakdown->value('total_multiplier'))->toBe(15)
        ->and($breakdown->total)->toBe(27 * 15);
});

test('2027: FT8 and satellite contacts count toward no band or mode objective', function () {
    $wfd = EventType::where('code', 'WFD')->firstOrFail();
    $event = Event::factory()->create(['event_type_id' => $wfd->id, 'rules_version' => '2027']);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id, 'max_power_watts' => 100]);
    $session = OperatingSession::factory()->create([
        'station_id' => Station::factory()->create(['event_configuration_id' => $config->id])->id,
    ]);

    // Five Phone bands x3, plus a sixth "band" made only of FT8 contacts, plus
    // satellite contacts: the only Digital contacts are FT8.
    $create = function (Mode $mode, Band $band, int $count, array $overrides = []) use ($config, $session) {
        for ($i = 0; $i < $count; $i++) {
            Contact::factory()->create([
                'event_configuration_id' => $config->id,
                'operating_session_id' => $session->id,
                'band_id' => $band->id,
                'mode_id' => $mode->id,
                'power_watts' => 100,
                'points' => (int) $mode->points_wfd,
                ...$overrides,
            ]);
        }
    };

    foreach ([80, 40, 20, 15, 10] as $meters) {
        $create($this->phone, Band::firstOrCreate(['name' => "{$meters}m"], ['meters' => $meters, 'frequency_mhz' => $meters]), 3);
    }
    $create($this->digital, Band::firstOrCreate(['name' => '6m'], ['meters' => 6, 'frequency_mhz' => 50]), 3, ['submode' => 'FT8']);
    $create($this->phone, Band::firstOrCreate(['name' => 'Satellite'], ['meters' => 1, 'frequency_mhz' => 435, 'is_satellite' => true]), 3, ['is_satellite' => true]);

    app(EventBonusReconciler::class)->reconcileAll($config);

    $codes = $config->bonuses()->with('bonusType')->get()->pluck('bonusType.code');

    // Only 5 creditable bands, and only the Phone mode group is used.
    expect($codes)->not->toContain('six_bands')
        ->and($codes)->not->toContain('multiple_modes')
        ->and(wfdScore($config)->value('qso_points'))->toBe(15);

    // The same log is a six-band, multi-mode log under the frozen 2026 rules.
    $event->update(['rules_version' => '2026']);
    $config->forgetResolvedRuleSet();
    app(RescoreService::class)->rescoreEvent($event->fresh());

    $codes = $config->bonuses()->with('bonusType')->get()->pluck('bonusType.code');
    expect($codes)->toContain('six_bands')->toContain('multiple_modes');
});

/**
 * Rescore a 2026 log into 2027 and back, returning the pieces to assert on.
 *
 * @return array{config: EventConfiguration, total2026: int, pointsBefore: array<int, int>}
 */
function wfdRoundTrip(bool $withFmSatellite): array
{
    ['config' => $config] = wfdRegressionFixture('2026');
    $event = $config->event;

    wfdClaim($config, '2026', 'away_from_home');
    if ($withFmSatellite) {
        wfdClaim($config, '2026', 'fm_satellite');
    }
    app(EventBonusReconciler::class)->reconcileAll($config);

    $pointsBefore = Contact::where('event_configuration_id', $config->id)->orderBy('id')->pluck('points', 'id')->all();
    $total2026 = wfdScore($config)->total;

    // 2026 -> 2027
    $event->update(['rules_version' => '2027']);
    app(RescoreService::class)->rescoreEvent($event->fresh());

    $pointsIn2027 = Contact::where('event_configuration_id', $config->id)->orderBy('id')->pluck('points', 'id')->all();
    // FT8 (2 -> 0) and satellite (1 -> 0) are zeroed; everything else is unchanged.
    expect(array_sum($pointsIn2027))->toBe(array_sum($pointsBefore) - 3);

    // alternative_power and fm_satellite have no 2027 code, so their claims are
    // invalidated. The user then claims the 2027 equivalent of alternative power.
    wfdClaim($config, '2027', 'alt_power_full');
    app(EventBonusReconciler::class)->reconcileAll($config);

    // 27 points x (six_bands 6 + multiple_modes 2 + away 3 + alt_power_full 2 + equipment 1 + 1) = 27 x 15.
    expect(wfdScore($config)->total)->toBe(27 * 15);

    // 2027 -> 2026
    $event->update(['rules_version' => '2026']);
    app(RescoreService::class)->rescoreEvent($event->fresh());

    return ['config' => $config, 'total2026' => $total2026, 'pointsBefore' => $pointsBefore];
}

test('rescoring 2026 -> 2027 -> 2026 restores the 2026 total and stored contact points', function () {
    ['config' => $config, 'total2026' => $total2026, 'pointsBefore' => $pointsBefore] = wfdRoundTrip(false);

    // Without fm_satellite: 29 points x (6 + 2 + 1 alternative_power + 3 away + 1) = 29 x 13.
    expect($total2026)->toBe(29 * 13)
        ->and(Contact::where('event_configuration_id', $config->id)->orderBy('id')->pluck('points', 'id')->all())->toBe($pointsBefore)
        ->and(wfdScore($config)->total)->toBe($total2026);
});

/**
 * RescoreService restores a manual claim that a 2027 rescore invalidated (fm_satellite
 * has no 2027 code) when the event is rescored back to 2026.
 */
test('a manual 2026 claim invalidated by 2027 is restored on rescoring back to 2026', function () {
    ['config' => $config, 'total2026' => $total2026] = wfdRoundTrip(true);

    expect($total2026)->toBe(29 * 15)
        ->and(wfdScore($config)->total)->toBe($total2026);
});

test('WinterFieldDay2026 is untouched: the original twelve strategies and 2026 objective rows', function () {
    expect(array_keys((new WinterFieldDay2026)->strategies()))->toBe([
        'alternative_power', 'away_from_home', 'multiple_antennas', 'fm_satellite',
        'ssb_cw_satellite', 'winlink_email', 'special_bulletin', 'six_bands',
        'twelve_bands', 'multiple_modes', 'qrp', 'six_continuous_hours',
    ]);

    $rows = BonusType::where('event_type_id', $this->wfd->id)
        ->where('rules_version', '2026')
        ->whereNotNull('objective_multiplier')
        ->orderBy('code')
        ->pluck('objective_multiplier', 'code')
        ->all();

    expect($rows)->toBe([
        'alternative_power' => 1, 'away_from_home' => 3, 'fm_satellite' => 2, 'multiple_antennas' => 1,
        'multiple_modes' => 2, 'qrp' => 4, 'six_bands' => 6, 'six_continuous_hours' => 2,
        'special_bulletin' => 1, 'ssb_cw_satellite' => 3, 'twelve_bands' => 6, 'winlink_email' => 1,
    ]);

    // No 2027-only code leaked into the 2026 rows.
    expect(BonusType::where('event_type_id', $this->wfd->id)->where('rules_version', '2026')
        ->whereIn('code', ['alt_power_full', 'alt_power_equipment', 'bulletin', 'sstv_image', 'crossband_repeater'])
        ->exists())->toBeFalse();
});
