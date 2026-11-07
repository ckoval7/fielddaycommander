<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Relays\RelayEnrollment;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Relay;
use App\Models\User;
use App\Support\RelayConnection;
use App\Support\StationMode;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    $this->config = EventConfiguration::factory()
        ->for(Event::factory()->state(['start_time' => now()->subHour(), 'end_time' => now()->addHour()]))
        ->create();

    $this->operator = User::factory()->create()->assignRole('Operator');
});

/**
 * The component on an allowed connection. Tests run as localhost.
 */
function relayScreen(User $user): mixed
{
    return Livewire::actingAs($user)->test(RelayEnrollment::class);
}

/**
 * The component over plain HTTP from another computer. Livewire's test client
 * drops custom headers on action calls, so the connection check is swapped
 * out. RelayApiTest covers the check itself, with real requests.
 */
function relayScreenOverLan(User $user): mixed
{
    test()->mock(RelayConnection::class)->shouldReceive('isAllowed')->andReturn(false);

    return relayScreen($user);
}

test('a code can be created over plain HTTP from the server itself', function () {
    relayScreen($this->operator)
        ->set('name', 'Phone Tent')
        ->call('issue')
        ->assertHasNoErrors();

    expect(Relay::count())->toBe(1);
});

test('every role from operator up can enroll relays by default', function (string $role) {
    expect(Role::findByName($role)->hasPermissionTo('enroll-relays'))->toBeTrue();
})->with(['System Administrator', 'Event Manager', 'Station Captain', 'Operator']);

test('the setup-only role can not enroll relays', function () {
    expect(Role::findByName('Config Only')->hasPermissionTo('enroll-relays'))->toBeFalse();
});

test('station mode never allows relay enrollment', function () {
    expect(StationMode::ALLOWED_PERMISSIONS)->not->toContain('enroll-relays')
        ->and(StationMode::ALLOWED_ROUTES)->not->toContain('relays.index');
});

test('someone without the permission is refused', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('relays.index'))->assertForbidden();
});

test('an operator creates a code, and only its hash is stored', function () {
    $screen = relayScreen($this->operator)
        ->set('name', 'Phone Tent')
        ->call('issue')
        ->assertHasNoErrors();

    $code = $screen->get('issuedCode');
    $relay = Relay::sole();

    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and($relay->name)->toBe('Phone Tent')
        ->and($relay->created_by_user_id)->toBe($this->operator->id)
        ->and($relay->event_configuration_id)->toBe($this->config->id)
        ->and($relay->enrollment_code_hash)->toBe(Relay::hashSecret(str_replace('-', '', $code)));

    $screen->assertSee($code);

    expect(AuditLog::where('action', 'relay.code_issued')->sole()->user_id)->toBe($this->operator->id);
});

test('a new code has a button that copies it', function () {
    $screen = relayScreen($this->operator)->set('name', 'Phone Tent')->call('issue');

    $screen->assertSeeHtml('data-test="copy-enrollment-code"')
        ->assertSeeHtml('aria-label="Copy enrollment code"')
        ->assertSeeHtml('$copy('.Js::from($screen->get('issuedCode')));
});

test('no code is created over plain HTTP from another computer', function () {
    relayScreenOverLan($this->operator)
        ->assertSee('HTTPS required for relays')
        ->assertDontSee('Create Code')
        ->set('name', 'Phone Tent')
        ->call('issue')
        ->assertHasErrors('name')
        ->assertSet('issuedCode', null);

    expect(Relay::count())->toBe(0);
});

test('a name is required', function () {
    relayScreen($this->operator)->call('issue')->assertHasErrors(['name' => 'required']);
});

test('no code is created for an event that has ended', function () {
    $this->config->event->update(['start_time' => now()->subDays(3), 'end_time' => now()->subDays(2)]);

    relayScreen($this->operator)->set('name', 'Phone Tent')->call('issue')->assertHasErrors('name');

    expect(Relay::count())->toBe(0);
});

test('the code goes away once it has been used', function () {
    $screen = relayScreen($this->operator)->set('name', 'Phone Tent')->call('issue');
    $code = $screen->get('issuedCode');

    $this->postJson('https://localhost/api/relay/v1/enroll', ['code' => $code])->assertCreated();

    $screen->call('checkIssuedCode')->assertSet('issuedCode', null)->assertDontSee($code)->assertSee('Enrolled');
});

test('operators see and revoke only the relays they enrolled', function () {
    $mine = Relay::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Mine', 'created_by_user_id' => $this->operator->id]);
    $theirs = Relay::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Theirs', 'created_by_user_id' => User::factory()->create()->id]);

    relayScreen($this->operator)
        ->assertSee('Mine')
        ->assertDontSee('Theirs')
        ->call('revoke', $theirs->id)
        ->assertForbidden();

    expect($theirs->refresh()->revoked_at)->toBeNull();

    relayScreen($this->operator)->call('revoke', $mine->id)->assertSee('Revoked');

    expect($mine->refresh()->revoked_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'relay.revoked')->sole()->user_id)->toBe($this->operator->id);
});

test('external logger managers see and revoke every relay', function () {
    $manager = User::factory()->create()->assignRole('Event Manager');
    $relay = Relay::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Theirs', 'created_by_user_id' => $this->operator->id]);

    relayScreen($manager)->assertSee('Theirs')->call('revoke', $relay->id)->assertOk();

    expect($relay->refresh()->revoked_at)->not->toBeNull();
});

test('revoking works over plain HTTP, so a relay can always be stopped', function () {
    $relay = Relay::factory()->create(['event_configuration_id' => $this->config->id, 'created_by_user_id' => $this->operator->id]);

    relayScreenOverLan($this->operator)->call('revoke', $relay->id)->assertOk();

    expect($relay->refresh()->revoked_at)->not->toBeNull();
});

test('relays from other events are not listed', function () {
    Relay::factory()->create(['name' => 'Other Event', 'created_by_user_id' => $this->operator->id]);

    relayScreen($this->operator)->assertDontSee('Other Event');
});

test('an unused, expired code is cleared when the same person creates another', function () {
    Relay::factory()->pending('OLD')->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Abandoned',
        'created_by_user_id' => $this->operator->id,
        'enrollment_expires_at' => now()->subMinute(),
    ]);

    relayScreen($this->operator)->assertDontSee('Abandoned')->set('name', 'Phone Tent')->call('issue');

    expect(Relay::pluck('name')->all())->toBe(['Phone Tent']);
});

test('tells people without manage-stations that the station must already exist', function () {
    relayScreen($this->operator)->assertSee('Match a station name or hostname');

    relayScreen(User::factory()->create()->assignRole('Station Captain'))->assertDontSee('Match a station name or hostname');
});
