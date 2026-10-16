<?php

use App\Http\Middleware\DemoMiddleware;
use App\Livewire\StationMode\Landing;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StationDevice;
use App\Models\User;
use App\Support\DemoCookie;
use App\Support\StationMode;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.shared_cache_store', 'array');

    DB::table('system_config')->updateOrInsert(['key' => 'setup_completed'], ['value' => 'true']);

    app()->bind(DemoMiddleware::class, fn () => new class extends DemoMiddleware
    {
        protected function demoDatabaseExists(string $dbName): bool
        {
            return true;
        }
    });

    foreach (['log-contacts', 'view-stations', 'manage-stations'] as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
    Role::firstOrCreate(['name' => 'Station Captain', 'guard_name' => 'web'])->givePermissionTo(['log-contacts', 'view-stations', 'manage-stations']);
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web'])->givePermissionTo(['log-contacts', 'view-stations']);

    $event = Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);
    Setting::set('active_event_id', $event->id);

    $this->station = Station::factory()->create(['event_configuration_id' => $eventConfig->id, 'name' => 'Demo HF']);
    $this->device = StationDevice::factory()->withToken('device-token')->create(['station_id' => $this->station->id]);

    $this->visitor = User::factory()->create(['call_sign' => 'K1VIS']);
    $this->visitor->assignRole('Station Captain');

    $this->demoCookie = Str::uuid().'|'.$this->visitor->id;
});

test('an enrolled demo browser is not signed back in as the visitor', function () {
    $this->withCookies([DemoCookie::NAME => $this->demoCookie, StationMode::COOKIE => 'device-token'])
        ->get(route('dashboard'))
        ->assertRedirect(route('station-mode.landing'));

    $this->assertGuest();
});

test('a walk-up operator keeps their own session in the demo', function () {
    $operator = User::factory()->ghost()->create(['call_sign' => 'KD2WLK']);

    $this->actingAs($operator)
        ->withSession([StationMode::SESSION_KEY => $this->device->id])
        ->withCookies([DemoCookie::NAME => $this->demoCookie, StationMode::COOKIE => 'device-token'])
        ->get(route('logbook.index'))
        ->assertOk();

    $this->assertAuthenticatedAs($operator);
});

test('the demo landing page offers a one-click exit', function () {
    Livewire::withCookies([DemoCookie::NAME => $this->demoCookie, StationMode::COOKIE => 'device-token'])
        ->test(Landing::class)
        ->assertSee('Demo: type any callsign to try walk-up logging')
        ->assertSee('K1VIS')
        ->assertDontSee('Station captain: exit station mode');
});

test('exiting station mode returns the visitor to their demo session', function () {
    Livewire::withCookies([DemoCookie::NAME => $this->demoCookie, StationMode::COOKIE => 'device-token'])
        ->test(Landing::class)
        ->call('exitStationMode')
        ->assertHasNoErrors()
        ->assertRedirect(route('stations.index'));

    expect($this->device->fresh()->isRevoked())->toBeTrue()
        ->and(session('dev_role_override'))->toBe('Station Captain');

    $this->assertAuthenticatedAs($this->visitor);
});

test('an invited operator can exit station mode the same as the host', function () {
    $guest = User::factory()->create(['call_sign' => 'N2GST']);
    $guest->assignRole('Operator');

    Livewire::withCookies([DemoCookie::NAME => Str::uuid().'|'.$guest->id, StationMode::COOKIE => 'device-token'])
        ->test(Landing::class)
        ->call('exitStationMode')
        ->assertRedirect(route('stations.index'));

    expect($this->device->fresh()->isRevoked())->toBeTrue();
    $this->assertAuthenticatedAs($guest);
});

test('a browser revoked elsewhere is signed back in as the visitor', function () {
    $this->device->update(['revoked_at' => now()]);
    $uuid = explode('|', $this->demoCookie)[0];
    Config::set('database.connections.demo.database', 'demo_'.str_replace('-', '_', $uuid));

    $this->withCookies([DemoCookie::NAME => $this->demoCookie, StationMode::COOKIE => 'device-token'])
        ->get(route('logbook.index'))
        ->assertOk();

    $this->assertAuthenticatedAs($this->visitor);
});
