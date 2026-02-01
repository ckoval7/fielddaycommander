<?php

use App\Livewire\Settings\SystemPreferences;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();
    $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage-settings']);
    $role->givePermissionTo('manage-settings');
    $this->user->assignRole($role);
    $this->actingAs($this->user);
});

test('component can mount', function () {
    Livewire::test(SystemPreferences::class)
        ->assertStatus(200);
});

test('loads settings from database', function () {
    Setting::set('timezone', 'America/Los_Angeles');
    Setting::set('date_format', 'm/d/Y');

    Livewire::test(SystemPreferences::class)
        ->assertSet('timezone', 'America/Los_Angeles')
        ->assertSet('date_format', 'm/d/Y');
});

test('validates timezone required', function () {
    Livewire::test(SystemPreferences::class)
        ->set('timezone', '')
        ->call('save')
        ->assertHasErrors(['timezone' => 'required']);
});

test('saves system preferences', function () {
    Livewire::test(SystemPreferences::class)
        ->set('timezone', 'America/Chicago')
        ->set('date_format', 'd/m/Y')
        ->set('time_format', 'h:i:s A')
        ->set('contact_email', 'test@example.com')
        ->call('save')
        ->assertDispatched('notify');

    expect(Setting::get('timezone'))->toBe('America/Chicago');
    expect(Setting::get('date_format'))->toBe('d/m/Y');
    expect(Setting::get('contact_email'))->toBe('test@example.com');
});

test('preview updates with format changes', function () {
    $component = Livewire::test(SystemPreferences::class)
        ->set('date_format', 'Y-m-d')
        ->set('time_format', 'H:i:s');

    // Check that preview is computed and contains expected format pattern
    expect($component->get('preview'))->toContain('-')->toContain(':');
});
