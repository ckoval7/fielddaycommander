<?php

use App\Livewire\Logging\StationSelect;
use App\Livewire\Logging\TranscribeSelect;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Seed all permissions
    $this->seed([\Database\Seeders\PermissionSeeder::class]);

    $this->configOnlyRole = Role::firstOrCreate(
        ['name' => 'Config Only', 'guard_name' => 'web']
    );
    $this->configOnlyRole->syncPermissions([
        'manage-users',
        'manage-roles',
        'manage-settings',
        'view-security-logs',
        'view-events',
        'view-reports',
        'view-stations',
        'view-all-equipment',
    ]);

    $this->configUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);
    $this->configUser->assignRole($this->configOnlyRole);

    $this->event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);
});

test('config only user cannot access transcribe select', function () {
    $this->actingAs($this->configUser);

    Livewire::test(TranscribeSelect::class)
        ->assertForbidden();
});

test('config only user cannot start operating session', function () {
    $this->actingAs($this->configUser);

    Livewire::test(StationSelect::class)
        ->assertForbidden();
});

test('config only user has admin permissions', function () {
    expect($this->configUser->can('manage-users'))->toBeTrue();
    expect($this->configUser->can('manage-roles'))->toBeTrue();
    expect($this->configUser->can('manage-settings'))->toBeTrue();
    expect($this->configUser->can('view-security-logs'))->toBeTrue();
});

test('config only user lacks human action permissions', function () {
    expect($this->configUser->can('log-contacts'))->toBeFalse();
    expect($this->configUser->can('edit-contacts'))->toBeFalse();
    expect($this->configUser->can('manage-bulletins'))->toBeFalse();
    expect($this->configUser->can('manage-shifts'))->toBeFalse();
    expect($this->configUser->can('manage-images'))->toBeFalse();
    expect($this->configUser->can('manage-guestbook'))->toBeFalse();
    expect($this->configUser->can('sign-guestbook'))->toBeFalse();
    expect($this->configUser->can('sign-up-shifts'))->toBeFalse();
});
