<?php

use App\Livewire\Profile\UserProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    Role::create(['name' => 'Station Captain', 'guard_name' => 'web']);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $this->user = User::factory()->create([
        'call_sign' => 'W1AW',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'password' => Hash::make('password123'),
        'license_class' => 'Extra',
        'preferred_timezone' => 'America/New_York',
        'notification_preferences' => [
            'event_notifications' => true,
            'system_announcements' => true,
        ],
    ]);
    $this->user->assignRole('Operator');

    $this->actingAs($this->user);
});

test('default category preferences are all enabled', function () {
    Livewire::test(UserProfile::class)
        ->assertSet('notify_new_section', true)
        ->assertSet('notify_guestbook', true)
        ->assertSet('notify_photos', true)
        ->assertSet('notify_station_status', true)
        ->assertSet('notify_qso_milestone', true)
        ->assertSet('notify_equipment', true);
});

test('saving category preferences persists to database', function () {
    Livewire::test(UserProfile::class)
        ->set('notify_new_section', false)
        ->set('notify_guestbook', false)
        ->set('notify_photos', true)
        ->set('notify_station_status', false)
        ->set('notify_qso_milestone', true)
        ->set('notify_equipment', false)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->user->refresh();
    $categories = $this->user->notification_preferences['categories'];

    expect($categories['new_section'])->toBeFalse()
        ->and($categories['guestbook'])->toBeFalse()
        ->and($categories['photos'])->toBeTrue()
        ->and($categories['station_status'])->toBeFalse()
        ->and($categories['qso_milestone'])->toBeTrue()
        ->and($categories['equipment'])->toBeFalse();
});

test('toggle all enables all categories', function () {
    Livewire::test(UserProfile::class)
        ->set('notify_new_section', false)
        ->set('notify_guestbook', false)
        ->set('notify_photos', false)
        ->call('toggleAllCategories', true)
        ->assertSet('notify_new_section', true)
        ->assertSet('notify_guestbook', true)
        ->assertSet('notify_photos', true)
        ->assertSet('notify_station_status', true)
        ->assertSet('notify_qso_milestone', true)
        ->assertSet('notify_equipment', true);
});

test('toggle all disables all categories', function () {
    Livewire::test(UserProfile::class)
        ->call('toggleAllCategories', false)
        ->assertSet('notify_new_section', false)
        ->assertSet('notify_guestbook', false)
        ->assertSet('notify_photos', false)
        ->assertSet('notify_station_status', false)
        ->assertSet('notify_qso_milestone', false)
        ->assertSet('notify_equipment', false);
});

test('individual category toggle works independently', function () {
    Livewire::test(UserProfile::class)
        ->set('notify_guestbook', false)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->user->refresh();
    $categories = $this->user->notification_preferences['categories'];

    expect($categories['new_section'])->toBeTrue()
        ->and($categories['guestbook'])->toBeFalse()
        ->and($categories['photos'])->toBeTrue()
        ->and($categories['station_status'])->toBeTrue()
        ->and($categories['qso_milestone'])->toBeTrue()
        ->and($categories['equipment'])->toBeTrue();
});

test('preferences load correctly on mount with saved categories', function () {
    $this->user->update([
        'notification_preferences' => [
            'event_notifications' => true,
            'system_announcements' => false,
            'categories' => [
                'new_section' => false,
                'guestbook' => true,
                'photos' => false,
                'station_status' => true,
                'qso_milestone' => false,
                'equipment' => true,
            ],
        ],
    ]);

    Livewire::test(UserProfile::class)
        ->assertSet('event_notifications', true)
        ->assertSet('system_announcements', false)
        ->assertSet('notify_new_section', false)
        ->assertSet('notify_guestbook', true)
        ->assertSet('notify_photos', false)
        ->assertSet('notify_station_status', true)
        ->assertSet('notify_qso_milestone', false)
        ->assertSet('notify_equipment', true);
});

test('allCategoriesEnabled is true when all categories enabled', function () {
    $component = Livewire::test(UserProfile::class);

    expect($component->get('allCategoriesEnabled'))->toBeTrue();
});

test('allCategoriesEnabled is false when any category disabled', function () {
    $component = Livewire::test(UserProfile::class)
        ->set('notify_photos', false);

    expect($component->get('allCategoriesEnabled'))->toBeFalse();
});

test('saving categories preserves existing email notification preferences', function () {
    Livewire::test(UserProfile::class)
        ->set('event_notifications', false)
        ->set('notify_new_section', false)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->user->refresh();
    $prefs = $this->user->notification_preferences;

    expect($prefs['event_notifications'])->toBeFalse()
        ->and($prefs['system_announcements'])->toBeTrue()
        ->and($prefs['categories']['new_section'])->toBeFalse()
        ->and($prefs['categories']['guestbook'])->toBeTrue();
});

test('notify_weather_alert defaults to true', function () {
    Livewire::test(UserProfile::class)
        ->assertSet('notify_weather_alert', true);
});

test('weather_alert_email defaults to false', function () {
    Livewire::test(UserProfile::class)
        ->assertSet('weather_alert_email', false);
});

test('saving notify_weather_alert persists to database', function () {
    Livewire::test(UserProfile::class)
        ->set('notify_weather_alert', false)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->user->refresh();
    $categories = $this->user->notification_preferences['categories'];

    expect($categories['weather_alert'])->toBeFalse();
});

test('saving weather_alert_email persists to database', function () {
    Livewire::test(UserProfile::class)
        ->set('weather_alert_email', true)
        ->call('saveProfile')
        ->assertHasNoErrors();

    $this->user->refresh();

    expect($this->user->notification_preferences['weather_alert_email'])->toBeTrue();
});

test('notify_weather_alert loads from saved preferences', function () {
    $this->user->update([
        'notification_preferences' => [
            'event_notifications' => true,
            'system_announcements' => true,
            'categories' => ['weather_alert' => false],
        ],
    ]);

    Livewire::test(UserProfile::class)
        ->assertSet('notify_weather_alert', false);
});

test('weather_alert_email loads from saved preferences', function () {
    $this->user->update([
        'notification_preferences' => [
            'event_notifications' => true,
            'system_announcements' => true,
            'weather_alert_email' => true,
        ],
    ]);

    Livewire::test(UserProfile::class)
        ->assertSet('weather_alert_email', true);
});

test('toggle all includes notify_weather_alert', function () {
    Livewire::test(UserProfile::class)
        ->set('notify_weather_alert', false)
        ->call('toggleAllCategories', true)
        ->assertSet('notify_weather_alert', true);

    Livewire::test(UserProfile::class)
        ->call('toggleAllCategories', false)
        ->assertSet('notify_weather_alert', false);
});

test('allCategoriesEnabled is false when notify_weather_alert is disabled', function () {
    $component = Livewire::test(UserProfile::class)
        ->set('notify_weather_alert', false);

    expect($component->get('allCategoriesEnabled'))->toBeFalse();
});
