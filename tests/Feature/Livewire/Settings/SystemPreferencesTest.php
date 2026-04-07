<?php

use App\Livewire\Settings\SystemPreferences;
use App\Models\AuditLog;
use App\Models\Organization;
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

    $this->organization = Organization::factory()->create([
        'name' => 'Test Radio Club',
        'callsign' => 'W1TEST',
        'is_active' => true,
    ]);
    Setting::set('default_organization_id', $this->organization->id);
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

test('grace period setting defaults to 30 on mount', function () {
    Livewire::test(SystemPreferences::class)
        ->assertSet('post_event_grace_period_days', 30);
});

test('grace period setting can be saved', function () {
    Livewire::test(SystemPreferences::class)
        ->set('post_event_grace_period_days', 14)
        ->call('save')
        ->assertDispatched('notify');

    expect(Setting::get('post_event_grace_period_days'))->toBe(14);
});

test('grace period setting validates minimum of 0', function () {
    Livewire::test(SystemPreferences::class)
        ->set('post_event_grace_period_days', -1)
        ->call('save')
        ->assertHasErrors(['post_event_grace_period_days' => 'min']);
});

test('grace period setting validates maximum of 365', function () {
    Livewire::test(SystemPreferences::class)
        ->set('post_event_grace_period_days', 366)
        ->call('save')
        ->assertHasErrors(['post_event_grace_period_days' => 'max']);
});

test('grace period setting loads from database', function () {
    Setting::set('post_event_grace_period_days', 7);

    Livewire::test(SystemPreferences::class)
        ->assertSet('post_event_grace_period_days', 7);
});

test('ICS-213 setting defaults to disabled', function () {
    Livewire::test(SystemPreferences::class)
        ->assertSet('enable_ics213', false);
});

test('ICS-213 setting can be enabled', function () {
    Livewire::test(SystemPreferences::class)
        ->set('enable_ics213', true)
        ->call('save')
        ->assertDispatched('notify');

    expect(Setting::getBoolean('enable_ics213'))->toBeTrue();
});

test('saving preferences logs to audit log', function () {
    Livewire::test(SystemPreferences::class)
        ->set('timezone', 'America/Chicago')
        ->set('date_format', 'd/m/Y')
        ->call('save');

    $auditLog = AuditLog::where('action', 'settings.updated')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->new_values['timezone'])->toBe('America/Chicago');
    expect($auditLog->new_values['date_format'])->toBe('d/m/Y');
});

test('loads organization information on mount', function () {
    Livewire::test(SystemPreferences::class)
        ->assertSet('organization_name', 'Test Radio Club')
        ->assertSet('organization_callsign', 'W1TEST');
});

test('saves organization information', function () {
    Livewire::test(SystemPreferences::class)
        ->set('organization_name', 'Updated Club Name')
        ->set('organization_callsign', 'K2NEW')
        ->set('organization_email', 'club@example.com')
        ->set('organization_phone', '555-123-4567')
        ->call('save')
        ->assertDispatched('notify');

    $this->organization->refresh();
    expect($this->organization->name)->toBe('Updated Club Name');
    expect($this->organization->callsign)->toBe('K2NEW');
    expect($this->organization->email)->toBe('club@example.com');
    expect($this->organization->phone)->toBe('555-123-4567');
});

test('validates organization name is required', function () {
    Livewire::test(SystemPreferences::class)
        ->set('organization_name', '')
        ->call('save')
        ->assertHasErrors(['organization_name' => 'required']);
});

test('validates organization callsign format', function () {
    Livewire::test(SystemPreferences::class)
        ->set('organization_callsign', 'invalid!')
        ->call('save')
        ->assertHasErrors(['organization_callsign' => 'regex']);
});

test('organization update logs to audit log', function () {
    Livewire::test(SystemPreferences::class)
        ->set('organization_name', 'Audit Test Club')
        ->call('save');

    $auditLog = AuditLog::where('action', 'organization.updated')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->new_values['name'])->toBe('Audit Test Club');
});
