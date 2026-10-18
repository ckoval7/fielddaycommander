<?php

use App\Livewire\StationMode\Landing;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationDevice;
use App\Models\User;
use App\Support\StationMode;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    Permission::firstOrCreate(['name' => 'log-contacts']);
    Permission::firstOrCreate(['name' => 'manage-stations']);
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web'])->givePermissionTo('log-contacts');
    Role::firstOrCreate(['name' => 'Station Captain', 'guard_name' => 'web'])->givePermissionTo(['log-contacts', 'manage-stations']);

    $event = Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $this->station = Station::factory()->create(['event_configuration_id' => $eventConfig->id, 'name' => 'GOTA']);
    $this->device = StationDevice::factory()->withToken('device-token')->create(['station_id' => $this->station->id]);
});

function landing(): Testable
{
    return Livewire::withCookie(StationMode::COOKIE, 'device-token')->test(Landing::class);
}

test('enrolled browser is sent to the station-mode landing page', function () {
    $this->withCookie(StationMode::COOKIE, 'device-token')
        ->get(route('dashboard'))
        ->assertRedirect(route('station-mode.landing'));

    $this->withCookie(StationMode::COOKIE, 'device-token')
        ->get(route('station-mode.landing'))
        ->assertOk()
        ->assertSee('GOTA');
});

test('landing page without an enrolled browser redirects to login', function () {
    $this->get(route('station-mode.landing'))
        ->assertRedirect(route('login'));
});

test('a revoked browser is not treated as enrolled', function () {
    $this->device->update(['revoked_at' => now()]);

    $this->withCookie(StationMode::COOKIE, 'device-token')
        ->get(route('station-mode.landing'))
        ->assertRedirect(route('login'));
});

test('new callsign asks for a name then creates a ghost account', function () {
    landing()
        ->set('callSign', 'kd2abc')
        ->call('start')
        ->assertSet('needsName', true)
        ->assertNoRedirect()
        ->set('firstName', 'Pat')
        ->set('lastName', 'Jones')
        ->call('start')
        ->assertHasNoErrors()
        ->assertRedirect(route('logging.station-select'));

    $user = User::where('call_sign', 'KD2ABC')->sole();

    expect($user->is_ghost)->toBeTrue()
        ->and($user->first_name)->toBe('Pat')
        ->and($user->last_name)->toBe('Jones')
        ->and($user->isLocked())->toBeTrue()
        ->and(auth()->id())->toBe($user->id)
        ->and(session(StationMode::SESSION_KEY))->toBe($this->device->id)
        ->and(AuditLog::where('action', 'station_mode.session_started')->exists())->toBeTrue();
});

test('new callsign requires a name', function () {
    landing()
        ->set('callSign', 'KD2ABC')
        ->call('start')
        ->call('start')
        ->assertHasErrors(['firstName' => 'required', 'lastName' => 'required']);

    expect(User::where('call_sign', 'KD2ABC')->exists())->toBeFalse();
});

test('existing ghost with a placeholder name gets the entered name', function () {
    $ghost = User::factory()->ghost()->create(['call_sign' => 'W2XYZ']);

    landing()
        ->set('callSign', 'W2XYZ')
        ->call('start')
        ->assertSet('needsName', true)
        ->set('firstName', 'Sam')
        ->set('lastName', 'Lee')
        ->call('start')
        ->assertRedirect(route('logging.station-select'));

    expect($ghost->fresh()->first_name)->toBe('Sam')
        ->and($ghost->fresh()->last_name)->toBe('Lee')
        ->and(User::where('call_sign', 'W2XYZ')->count())->toBe(1)
        ->and(auth()->id())->toBe($ghost->id);
});

test('real account signs in by callsign without a password', function () {
    $member = User::factory()->create(['call_sign' => 'K3CPK']);
    $member->assignRole('Station Captain');

    landing()
        ->set('callSign', 'k3cpk')
        ->call('start')
        ->assertSet('needsName', false)
        ->assertRedirect(route('logging.station-select'));

    expect(auth()->id())->toBe($member->id);
});

test('system, deleted and locked real accounts are refused', function (string $callSign, Closure $makeUser) {
    $makeUser($callSign);

    landing()
        ->set('callSign', $callSign)
        ->call('start')
        ->assertHasErrors('callSign')
        ->assertNoRedirect();

    $this->assertGuest();
})->with([
    'system user' => ['SYSTEM', fn (string $callSign) => User::factory()->create(['call_sign' => $callSign])],
    'deleted user' => ['N1DEL', fn (string $callSign) => User::factory()->create(['call_sign' => $callSign])->delete()],
    'locked user' => ['N1LCK', fn (string $callSign) => User::factory()->create(['call_sign' => $callSign, 'account_locked_at' => now()])],
]);

test('captain credentials take the browser out of station mode', function () {
    $captain = User::factory()->create(['email' => 'captain@example.com']);
    $captain->assignRole('Station Captain');

    landing()
        ->call('openExitModal')
        ->set('exitEmail', 'captain@example.com')
        ->set('exitPassword', 'password')
        ->call('exitStationMode')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    expect($this->device->fresh()->isRevoked())->toBeTrue()
        ->and(AuditLog::where('action', 'station.device_exited')->where('user_id', $captain->id)->exists())->toBeTrue();
});

test('operator credentials cannot take the browser out of station mode', function () {
    $operator = User::factory()->create(['email' => 'op@example.com']);
    $operator->assignRole('Operator');

    landing()
        ->set('exitEmail', 'op@example.com')
        ->set('exitPassword', 'password')
        ->call('exitStationMode')
        ->assertHasErrors('exitEmail');

    expect($this->device->fresh()->isRevoked())->toBeFalse();
});

test('captain can sign in on an enrolled browser and exit station mode', function () {
    $captain = User::factory()->create(['email' => 'captain@example.com']);
    $captain->assignRole('Station Captain');

    $this->withCookie(StationMode::COOKIE, 'device-token')
        ->post(route('login.store'), ['email' => 'captain@example.com', 'password' => 'password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($captain);

    $this->withCookie(StationMode::COOKIE, 'device-token')
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Station Mode Browser');

    landing()
        ->assertSee('Exit Station Mode')
        ->call('exitStationMode')
        ->assertRedirect(route('stations.index'));

    expect($this->device->fresh()->isRevoked())->toBeTrue();
});

test('signed-in user signs out of the station with a POST form', function () {
    $captain = User::factory()->create(['email' => 'captain@example.com']);
    $captain->assignRole('Station Captain');

    $this->withCookie(StationMode::COOKIE, 'device-token')
        ->post(route('login.store'), ['email' => 'captain@example.com', 'password' => 'password']);

    landing()
        ->assertSee('Sign Out')
        ->assertSeeHtml('method="POST" action="'.route('logout').'"')
        ->assertDontSeeHtml('href="'.url('/logout').'"');
});

test('gota station asks the control operator to start the session', function () {
    $this->station->update(['is_gota' => true]);

    landing()
        ->assertSee('GOTA station: control operator signs in')
        ->assertSee('Control operator callsign');
});

test('non-gota station does not show control operator instructions', function () {
    landing()
        ->assertDontSee('GOTA station: control operator signs in')
        ->assertSee('Your callsign');
});

test('starting a session clears developer role and callsign overrides', function () {
    config(['developer.enabled' => true]);
    session(['dev_role_override' => 'Station Captain', 'dev_callsign_override' => 'N0FAKE']);

    landing()
        ->set('callSign', 'KD2ABC')
        ->call('start')
        ->set('firstName', 'Pat')
        ->set('lastName', 'Jones')
        ->call('start')
        ->assertRedirect(route('logging.station-select'));

    $ghost = User::where('call_sign', 'KD2ABC')->sole();

    expect(session()->has('dev_role_override'))->toBeFalse()
        ->and(session()->has('dev_callsign_override'))->toBeFalse()
        ->and($ghost->effectiveCallSign())->toBe('KD2ABC')
        ->and($ghost->roles)->toBeEmpty();
});
