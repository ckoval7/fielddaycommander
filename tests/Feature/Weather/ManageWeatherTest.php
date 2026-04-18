<?php

use App\Events\WeatherAlertChanged;
use App\Livewire\Weather\ManageWeather;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActiveEventService;
use App\Services\WeatherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Permission::firstOrCreate(['name' => 'manage-weather']);
});

test('users without manage-weather permission cannot access the page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('weather.manage'))
        ->assertForbidden();
});

test('users with manage-weather permission can access the page', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    $this->actingAs($user)
        ->get(route('weather.manage'))
        ->assertOk();
});

test('activating override stores data in system_config', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->set('temperature', 75)
        ->set('windSpeed', 12)
        ->set('windDirection', 'SW')
        ->set('precipitationChance', 30)
        ->set('notes', 'Storm cells approaching from west')
        ->call('activateOverride')
        ->assertHasNoErrors();

    $stored = Setting::get('weather.manual_override');
    expect($stored)->not->toBeNull();
    expect($stored['temperature'])->toBe(75);
    expect($stored['notes'])->toBe('Storm cells approaching from west');
    expect($stored['updated_by'])->toBe($user->call_sign);
});

test('clearing override removes data from system_config', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Setting::set('weather.manual_override', ['temperature' => 72, 'notes' => '']);

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->call('clearOverride');

    expect(Setting::get('weather.manual_override'))->toBeNull();
});

test('triggering manual alert stores and broadcasts', function () {
    Event::fake([WeatherAlertChanged::class]);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->set('alertMessage', 'Lightning within 5 miles — all antennas down now')
        ->call('triggerAlert')
        ->assertHasNoErrors();

    $alerts = Setting::get('weather.alerts');
    expect($alerts[0]['headline'])->toBe('Lightning within 5 miles — all antennas down now');

    Event::assertDispatched(WeatherAlertChanged::class, fn ($e) => $e->manual === true);
});

test('clearing manual alert broadcasts all-clear', function () {
    Event::fake([WeatherAlertChanged::class]);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->call('clearAlert');

    Event::assertDispatched(WeatherAlertChanged::class, fn ($e) => $e->hasAlerts === false && $e->manual === true);
});

test('validation requires temperature when activating override', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->set('temperature', null)
        ->call('activateOverride')
        ->assertHasErrors(['temperature']);
});

test('validation requires alert message when triggering alert', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->set('alertMessage', '')
        ->call('triggerAlert')
        ->assertHasErrors(['alertMessage']);
});

test('manage weather page shows not fetched yet when no cached status', function () {
    cache()->forget('weather.forecast_status');
    cache()->forget('weather.alerts_status');

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('Not fetched yet');
});

test('manage weather page shows OK badge when both APIs succeed', function () {
    cache()->put('weather.forecast_status', [
        'last_attempt' => now()->toIso8601String(),
        'success' => true,
        'error' => null,
    ], now()->addHours(2));

    cache()->put('weather.alerts_status', [
        'last_attempt' => now()->toIso8601String(),
        'success' => true,
        'error' => null,
    ], now()->addHours(2));

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('OK');
});

test('manage weather page shows error badge and message when API fails', function () {
    cache()->forget('weather.alerts_status');

    cache()->put('weather.forecast_status', [
        'last_attempt' => now()->toIso8601String(),
        'success' => false,
        'error' => 'HTTP 503',
    ], now()->addHours(2));

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('HTTP 503');
});

test('saveUnits stores imperial setting', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->call('saveUnits', 'imperial')
        ->assertHasNoErrors()
        ->assertSet('units', 'imperial');

    expect(Setting::get('weather.units'))->toBe('imperial');
});

test('saveUnits stores metric setting', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->call('saveUnits', 'metric')
        ->assertHasNoErrors()
        ->assertSet('units', 'metric');

    expect(Setting::get('weather.units'))->toBe('metric');
});

test('manage weather form shows metric labels when metric is active', function () {
    Setting::set('weather.units', 'metric');

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('Temp (°C)')
        ->assertSee('Wind (km/h)');
});

test('override activation rejects temperature above metric ceiling', function () {
    Setting::set('weather.units', 'metric');

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->set('temperature', 70) // valid °F but out of range for °C (max 60)
        ->set('windSpeed', 15)
        ->set('windDirection', 'N')
        ->set('precipitationChance', 50)
        ->call('activateOverride')
        ->assertHasErrors(['temperature']);
});

// --- API toggles ---

test('toggleOpenMeteo disables Open-Meteo and clears forecast when currently enabled', function () {
    Setting::set('weather.openmeteo_enabled', true);
    Setting::set('weather.forecast', ['current' => ['temperature_2m' => 72.5]]);
    Setting::set('weather.last_fetch', now()->toIso8601String());

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSet('openMeteoEnabled', true)
        ->call('toggleOpenMeteo')
        ->assertSet('openMeteoEnabled', false);

    expect(Setting::get('weather.openmeteo_enabled'))->toBeFalsy();
    expect(Setting::get('weather.forecast'))->toBeNull();
});

test('toggleOpenMeteo enables Open-Meteo when currently disabled', function () {
    Setting::set('weather.openmeteo_enabled', false);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSet('openMeteoEnabled', false)
        ->call('toggleOpenMeteo')
        ->assertSet('openMeteoEnabled', true);

    expect(Setting::get('weather.openmeteo_enabled'))->toBeTruthy();
});

test('toggleOpenMeteo triggers immediate forecast fetch when enabling with active event', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => ['temperature_2m' => 68.0],
            'hourly' => ['time' => []],
            'daily' => ['time' => []],
        ], 200),
    ]);

    $event = App\Models\Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'latitude' => 41.3083,
        'longitude' => -72.9279,
        'state' => 'CT',
    ]);

    Setting::set('weather.openmeteo_enabled', false);
    app(ActiveEventService::class)->clearCache();

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->call('toggleOpenMeteo')
        ->assertSet('openMeteoEnabled', true);

    Http::assertSentCount(1);
    expect(Setting::get('weather.forecast'))->not->toBeNull();
});

test('toggleNws disables NWS and clears alerts when currently enabled', function () {
    Event::fake([WeatherAlertChanged::class]);

    Setting::set('weather.nws_enabled', true);
    Setting::set('weather.alerts', [
        ['event' => 'Tornado Warning', 'headline' => 'Tornado Warning', 'description' => '', 'severity' => 'Extreme', 'expires' => null],
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSet('nwsEnabled', true)
        ->call('toggleNws')
        ->assertSet('nwsEnabled', false);

    expect(Setting::get('weather.nws_enabled'))->toBeFalsy();
    expect(Setting::get('weather.alerts'))->toBeEmpty();
});

test('toggleNws enables NWS when currently disabled', function () {
    Setting::set('weather.nws_enabled', false);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSet('nwsEnabled', false)
        ->call('toggleNws')
        ->assertSet('nwsEnabled', true);

    expect(Setting::get('weather.nws_enabled'))->toBeTruthy();
});

test('manage weather component loads openMeteoEnabled and nwsEnabled from settings', function () {
    Setting::set('weather.openmeteo_enabled', false);
    Setting::set('weather.nws_enabled', false);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSet('openMeteoEnabled', false)
        ->assertSet('nwsEnabled', false);
});

test('getResolvedOpenMeteoLocation returns resolved coords, elevation, timezone from stored forecast', function () {
    Setting::set('weather.forecast', [
        'latitude' => 41.31,
        'longitude' => -72.93,
        'elevation' => 12.0,
        'timezone' => 'America/New_York',
        'timezone_abbreviation' => 'EDT',
        'current' => ['temperature_2m' => 68.0],
    ]);

    $resolved = app(WeatherService::class)->getResolvedOpenMeteoLocation();

    expect($resolved)->toMatchArray([
        'lat' => 41.31,
        'lon' => -72.93,
        'elevation' => 12.0,
        'timezone' => 'America/New_York',
        'timezone_abbreviation' => 'EDT',
    ]);
});

test('getResolvedOpenMeteoLocation returns null when forecast has not been stored', function () {
    Setting::set('weather.forecast', null);

    $resolved = app(WeatherService::class)->getResolvedOpenMeteoLocation();

    expect($resolved)->toBeNull();
});

test('getResolvedNwsLocation returns zone, county, city, state when points cache and location setting are populated', function () {
    $event = App\Models\Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'latitude' => 41.30834,
        'longitude' => -72.92791,
        'state' => 'CT',
    ]);
    app(ActiveEventService::class)->clearCache();

    cache()->forever('weather.nws_points.41.3083,-72.9279', [
        'zone' => 'CTZ006',
        'county' => 'CTC009',
    ]);
    Setting::set('weather.location', ['city' => 'New Haven', 'state' => 'CT']);

    $resolved = app(WeatherService::class)->getResolvedNwsLocation();

    expect($resolved)->toMatchArray([
        'zone' => 'CTZ006',
        'county' => 'CTC009',
        'city' => 'New Haven',
        'state' => 'CT',
    ]);
});

test('getResolvedNwsLocation returns null when there is no active event location', function () {
    $resolved = app(WeatherService::class)->getResolvedNwsLocation();

    expect($resolved)->toBeNull();
});

test('getResolvedNwsLocation returns null when points cache is empty even if event has location', function () {
    $event = App\Models\Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'latitude' => 41.30834,
        'longitude' => -72.92791,
        'state' => 'CT',
    ]);
    app(ActiveEventService::class)->clearCache();

    $resolved = app(WeatherService::class)->getResolvedNwsLocation();

    expect($resolved)->toBeNull();
});

test('ManageWeather exposes resolvedOpenMeteoLocation when forecast is stored', function () {
    Setting::set('weather.forecast', [
        'latitude' => 41.31,
        'longitude' => -72.93,
        'elevation' => 12.0,
        'timezone' => 'America/New_York',
        'timezone_abbreviation' => 'EDT',
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('America/New_York')
        ->assertSee('41.31');
});

test('ManageWeather exposes resolvedNwsLocation when points cache is populated', function () {
    $event = App\Models\Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'latitude' => 41.30834,
        'longitude' => -72.92791,
        'state' => 'CT',
    ]);
    app(ActiveEventService::class)->clearCache();

    cache()->forever('weather.nws_points.41.3083,-72.9279', [
        'zone' => 'CTZ006',
        'county' => 'CTC009',
    ]);
    Setting::set('weather.location', ['city' => 'New Haven', 'state' => 'CT']);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('CTZ006')
        ->assertSee('New Haven');
});

test('Open-Meteo panel renders empty-state helper when no forecast has been fetched', function () {
    Setting::set('weather.forecast', null);
    cache()->forget('weather.forecast_status');

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('No active event location configured.')
        ->assertSee('No fetch has been attempted yet.');
});

test('NWS panel renders empty-state helper when points cache is empty', function () {
    cache()->forget('weather.alerts_status');
    Setting::set('weather.location', null);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('No fetch has been attempted yet.');
});

test('NWS panel lists the allowed event types in the filter disclosure', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('Tornado Warning')
        ->assertSee('Severe Thunderstorm Warning')
        ->assertSee('Ice Storm Warning');
});

test('NWS panel renders the points URL with 4-decimal rounded coordinates', function () {
    $event = App\Models\Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'latitude' => 41.30834567,
        'longitude' => -72.92791234,
        'state' => 'CT',
    ]);
    app(ActiveEventService::class)->clearCache();

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('https://api.weather.gov/points/41.3083,-72.9279');
});

test('Open-Meteo disabled state shows polling-disabled helper inside panel', function () {
    Setting::set('weather.openmeteo_enabled', false);

    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(ManageWeather::class)
        ->assertSee('Polling is disabled');
});
