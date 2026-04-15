<?php

use App\Events\WeatherAlertChanged;
use App\Livewire\Weather\ManageWeather;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
