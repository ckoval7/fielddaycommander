<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Agents\AgentEnrollment;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\User;
use App\Support\AgentConnection;
use App\Support\StationMode;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
function agentScreen(User $user): mixed
{
    return Livewire::actingAs($user)->test(AgentEnrollment::class);
}

/**
 * The component over plain HTTP from another computer. Livewire's test client
 * drops custom headers on action calls, so the connection check is swapped
 * out. AgentApiTest covers the check itself, with real requests.
 */
function agentScreenOverLan(User $user): mixed
{
    test()->mock(AgentConnection::class)->shouldReceive('isAllowed')->andReturn(false);

    return agentScreen($user);
}

test('a code can be created over plain HTTP from the server itself', function () {
    agentScreen($this->operator)
        ->set('name', 'Phone Tent')
        ->call('issue')
        ->assertHasNoErrors();

    expect(Agent::count())->toBe(1);
});

test('every role from operator up can enroll agents by default', function (string $role) {
    expect(Role::findByName($role)->hasPermissionTo('enroll-agents'))->toBeTrue();
})->with(['System Administrator', 'Event Manager', 'Station Captain', 'Operator']);

test('the setup-only role can not enroll agents', function () {
    expect(Role::findByName('Config Only')->hasPermissionTo('enroll-agents'))->toBeFalse();
});

test('station mode never allows agent enrollment', function () {
    expect(StationMode::ALLOWED_PERMISSIONS)->not->toContain('enroll-agents')
        ->and(StationMode::ALLOWED_ROUTES)->not->toContain('agents.index');
});

test('someone without the permission is refused', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('agents.index'))->assertForbidden();
});

test('an operator creates a code, and only its hash is stored', function () {
    $screen = agentScreen($this->operator)
        ->set('name', 'Phone Tent')
        ->call('issue')
        ->assertHasNoErrors();

    $code = $screen->get('issuedCode');
    $agent = Agent::sole();

    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and($agent->name)->toBe('Phone Tent')
        ->and($agent->created_by_user_id)->toBe($this->operator->id)
        ->and($agent->event_configuration_id)->toBe($this->config->id)
        ->and($agent->enrollment_code_hash)->toBe(Agent::hashSecret(str_replace('-', '', $code)));

    $screen->assertSee($code);

    expect(AuditLog::where('action', 'agent.code_issued')->sole()->user_id)->toBe($this->operator->id);
});

test('no code is created over plain HTTP from another computer', function () {
    agentScreenOverLan($this->operator)
        ->assertSee('HTTPS required for agents')
        ->assertDontSee('Create Code')
        ->set('name', 'Phone Tent')
        ->call('issue')
        ->assertHasErrors('name')
        ->assertSet('issuedCode', null);

    expect(Agent::count())->toBe(0);
});

test('a name is required', function () {
    agentScreen($this->operator)->call('issue')->assertHasErrors(['name' => 'required']);
});

test('no code is created for an event that has ended', function () {
    $this->config->event->update(['start_time' => now()->subDays(3), 'end_time' => now()->subDays(2)]);

    agentScreen($this->operator)->set('name', 'Phone Tent')->call('issue')->assertHasErrors('name');

    expect(Agent::count())->toBe(0);
});

test('the code goes away once it has been used', function () {
    $screen = agentScreen($this->operator)->set('name', 'Phone Tent')->call('issue');
    $code = $screen->get('issuedCode');

    $this->postJson('https://localhost/api/agent/v1/enroll', ['code' => $code])->assertCreated();

    $screen->call('checkIssuedCode')->assertSet('issuedCode', null)->assertDontSee($code)->assertSee('Enrolled');
});

test('operators see and revoke only the agents they enrolled', function () {
    $mine = Agent::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Mine', 'created_by_user_id' => $this->operator->id]);
    $theirs = Agent::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Theirs', 'created_by_user_id' => User::factory()->create()->id]);

    agentScreen($this->operator)
        ->assertSee('Mine')
        ->assertDontSee('Theirs')
        ->call('revoke', $theirs->id)
        ->assertForbidden();

    expect($theirs->refresh()->revoked_at)->toBeNull();

    agentScreen($this->operator)->call('revoke', $mine->id)->assertSee('Revoked');

    expect($mine->refresh()->revoked_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'agent.revoked')->sole()->user_id)->toBe($this->operator->id);
});

test('external logger managers see and revoke every agent', function () {
    $manager = User::factory()->create()->assignRole('Event Manager');
    $agent = Agent::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Theirs', 'created_by_user_id' => $this->operator->id]);

    agentScreen($manager)->assertSee('Theirs')->call('revoke', $agent->id)->assertOk();

    expect($agent->refresh()->revoked_at)->not->toBeNull();
});

test('revoking works over plain HTTP, so an agent can always be stopped', function () {
    $agent = Agent::factory()->create(['event_configuration_id' => $this->config->id, 'created_by_user_id' => $this->operator->id]);

    agentScreenOverLan($this->operator)->call('revoke', $agent->id)->assertOk();

    expect($agent->refresh()->revoked_at)->not->toBeNull();
});

test('agents from other events are not listed', function () {
    Agent::factory()->create(['name' => 'Other Event', 'created_by_user_id' => $this->operator->id]);

    agentScreen($this->operator)->assertDontSee('Other Event');
});

test('an unused, expired code is cleared when the same person creates another', function () {
    Agent::factory()->pending('OLD')->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Abandoned',
        'created_by_user_id' => $this->operator->id,
        'enrollment_expires_at' => now()->subMinute(),
    ]);

    agentScreen($this->operator)->assertDontSee('Abandoned')->set('name', 'Phone Tent')->call('issue');

    expect(Agent::pluck('name')->all())->toBe(['Phone Tent']);
});

test('tells people without manage-stations that the station must already exist', function () {
    agentScreen($this->operator)->assertSee('must already exist');

    agentScreen(User::factory()->create()->assignRole('Station Captain'))->assertDontSee('must already exist');
});
