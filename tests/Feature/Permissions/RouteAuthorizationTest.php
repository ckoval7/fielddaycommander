<?php

use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Message;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HTTP-level authorization for every permission-gated route in routes/web.php
|--------------------------------------------------------------------------
|
| Each dataset row is [route name, required permission, route parameters].
| Route parameters are bound closures, resolved after beforeEach so they can
| reference the fixtures created there.
|
*/

beforeEach(function () {
    config(['developer.enabled' => true]);
    $this->seed(PermissionSeeder::class);

    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true'],
    );

    $this->user = User::factory()->create();

    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);
    $this->eventConfiguration = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->eventConfiguration->id]);
    $this->equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $this->message = Message::factory()->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'user_id' => $this->user->id,
    ]);
    $this->operatingSession = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
    ]);
});

$gatedPages = [
    'logging.station-select' => ['logging.station-select', 'log-contacts', fn () => []],
    'logging.session' => ['logging.session', 'log-contacts', fn () => ['operatingSession' => $this->operatingSession]],
    'logging.transcribe.select' => ['logging.transcribe.select', 'log-contacts', fn () => []],
    'logging.transcribe.session' => ['logging.transcribe.session', 'log-contacts', fn () => ['station' => $this->station]],
    'stations.index' => ['stations.index', 'view-stations', fn () => []],
    'stations.create' => ['stations.create', 'manage-stations', fn () => []],
    'stations.edit' => ['stations.edit', 'manage-stations', fn () => ['station' => $this->station]],
    'equipment.all' => ['equipment.all', 'view-all-equipment', fn () => []],
    'equipment.index' => ['equipment.index', 'manage-own-equipment', fn () => []],
    'equipment.create' => ['equipment.create', 'manage-own-equipment', fn () => []],
    'equipment.edit' => ['equipment.edit', 'manage-own-equipment', fn () => ['equipment' => $this->equipment]],
    'events.index' => ['events.index', 'view-events', fn () => []],
    'events.create' => ['events.create', 'create-events', fn () => []],
    'events.edit' => ['events.edit', 'edit-events', fn () => ['eventId' => $this->event->id]],
    'events.clone' => ['events.clone', 'create-events', fn () => ['eventId' => $this->event->id]],
    'events.show' => ['events.show', 'view-events', fn () => ['event' => $this->event]],
    'events.guestbook' => ['events.guestbook', 'manage-guestbook', fn () => ['event' => $this->event]],
    'events.messages.index' => ['events.messages.index', 'log-contacts', fn () => ['event' => $this->event]],
    'events.messages.create' => ['events.messages.create', 'log-contacts', fn () => ['event' => $this->event]],
    'events.messages.edit' => ['events.messages.edit', 'log-contacts', fn () => ['event' => $this->event, 'message' => $this->message]],
    'events.messages.print-all' => ['events.messages.print-all', 'log-contacts', fn () => ['event' => $this->event]],
    'events.messages.print' => ['events.messages.print', 'log-contacts', fn () => ['event' => $this->event, 'message' => $this->message]],
    'schedule.manage' => ['schedule.manage', 'manage-shifts', fn () => []],
    'site-safety.manage' => ['site-safety.manage', 'manage-shifts', fn () => []],
    'events.equipment.reports.commitment-summary' => ['events.equipment.reports.commitment-summary', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'events.equipment.reports.delivery-checklist' => ['events.equipment.reports.delivery-checklist', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'events.equipment.reports.station-inventory-csv' => ['events.equipment.reports.station-inventory-csv', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'events.equipment.reports.owner-contacts-csv' => ['events.equipment.reports.owner-contacts-csv', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'events.equipment.reports.return-checklist' => ['events.equipment.reports.return-checklist', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'events.equipment.reports.incident-report-csv' => ['events.equipment.reports.incident-report-csv', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'events.equipment.reports.historical-record' => ['events.equipment.reports.historical-record', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'users.index' => ['users.index', 'manage-users', fn () => []],
    'settings.index' => ['settings.index', 'manage-settings', fn () => []],
    'reports.index' => ['reports.index', 'view-reports', fn () => []],
    'admin.audit-logs' => ['admin.audit-logs', 'view-security-logs', fn () => []],
    'admin.import-adif' => ['admin.import-adif', 'import-contacts', fn () => []],
    'admin.external-loggers' => ['admin.external-loggers', 'import-contacts', fn () => []],
    'admin.developer' => ['admin.developer', 'manage-settings', fn () => []],
    'weather.manage' => ['weather.manage', 'manage-weather', fn () => []],
];

dataset('permission gated pages', $gatedPages);

/**
 * Gated routes whose success response depends on extra state (generated
 * files, PDFs, export payloads), so only the deny paths are asserted here.
 */
$gatedEndpoints = [
    'events.equipment.reports.station-inventory-pdf' => ['get', 'events.equipment.reports.station-inventory-pdf', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'events.equipment.reports.owner-contacts-pdf' => ['get', 'events.equipment.reports.owner-contacts-pdf', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'events.equipment.reports.incident-report-pdf' => ['get', 'events.equipment.reports.incident-report-pdf', 'manage-event-equipment', fn () => ['event' => $this->event]],
    'reports.cabrillo' => ['get', 'reports.cabrillo', 'view-reports', fn () => []],
    'reports.adif' => ['get', 'reports.adif', 'view-reports', fn () => []],
    'reports.wfd-event-summary' => ['get', 'reports.wfd-event-summary', 'view-reports', fn () => []],
    'reports.submission-sheet' => ['get', 'reports.submission-sheet', 'view-reports', fn () => []],
    'album-export.store' => ['post', 'album-export.store', 'manage-images', fn () => ['eventConfiguration' => $this->eventConfiguration]],
    'album-export.download' => ['get', 'album-export.download', 'manage-images', fn () => ['eventConfiguration' => $this->eventConfiguration, 'filename' => 'album.zip']],
    'logging.contacts.store' => ['post', 'logging.contacts.store', 'log-contacts', fn () => []],
];

dataset('permission gated endpoints', $gatedEndpoints);

test('guests are redirected to login from gated pages', function (string $routeName, string $permission, array $parameters) {
    $this->get(route($routeName, $parameters))
        ->assertRedirect(route('login'));
})->with('permission gated pages');

test('users without the required permission are forbidden from gated pages', function (string $routeName, string $permission, array $parameters) {
    $this->actingAs($this->user)
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with('permission gated pages');

test('users with the required permission can open gated pages', function (string $routeName, string $permission, array $parameters) {
    $this->user->givePermissionTo($permission);

    $response = $this->actingAs($this->user)->get(route($routeName, $parameters));

    if ($routeName === 'logging.station-select') {
        // The fixture user has an active operating session, so the picker forwards them to it.
        $response->assertRedirect(route('logging.session', $this->operatingSession));

        return;
    }

    $response->assertOk();
})->with('permission gated pages');

test('guests are redirected to login from gated endpoints', function (string $method, string $routeName, string $permission, array $parameters) {
    $this->{$method}(route($routeName, $parameters))
        ->assertRedirect(route('login'));
})->with('permission gated endpoints');

test('users without the required permission are forbidden from gated endpoints', function (string $method, string $routeName, string $permission, array $parameters) {
    $this->actingAs($this->user)
        ->{$method}(route($routeName, $parameters))
        ->assertForbidden();
})->with('permission gated endpoints');

test('message print returns 404 when the message belongs to another event', function () {
    $otherEvent = Event::factory()->create();
    EventConfiguration::factory()->create(['event_id' => $otherEvent->id]);
    $this->user->givePermissionTo('log-contacts');

    $this->actingAs($this->user)
        ->get(route('events.messages.print', ['event' => $otherEvent, 'message' => $this->message]))
        ->assertNotFound();
});

test('developer tools return 404 for permitted users when developer mode is disabled', function () {
    config(['developer.enabled' => false]);
    $this->user->givePermissionTo('manage-settings');

    $this->actingAs($this->user)
        ->get(route('admin.developer'))
        ->assertNotFound();
});

test('users with an unrelated permission are still forbidden', function () {
    $this->user->givePermissionTo('manage-roles');

    $this->actingAs($this->user)
        ->get(route('settings.index'))
        ->assertForbidden();
});

test('every can: gated route in routes/web.php is covered by a dataset', function () use ($gatedPages, $gatedEndpoints) {
    $gatedRouteNames = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (IlluminateRoute $route) => collect($route->gatherMiddleware())
            ->contains(fn (mixed $middleware) => is_string($middleware) && str_starts_with($middleware, 'can:')))
        ->map(fn (IlluminateRoute $route) => $route->getName())
        ->filter()
        ->sort()
        ->values()
        ->all();

    $coveredRouteNames = collect([...array_keys($gatedPages), ...array_keys($gatedEndpoints)])
        ->sort()
        ->values()
        ->all();

    expect($coveredRouteNames)->toBe($gatedRouteNames);
});

test('dataset permissions match the can: middleware on each route', function () use ($gatedPages, $gatedEndpoints) {
    $expectedPermissions = collect($gatedPages)->map(fn (array $row) => $row[1])
        ->merge(collect($gatedEndpoints)->map(fn (array $row) => $row[2]));

    foreach ($expectedPermissions as $routeName => $permission) {
        $middleware = Route::getRoutes()->getByName($routeName)->gatherMiddleware();

        expect(in_array("can:{$permission}", $middleware, true))
            ->toBeTrue("Route {$routeName} is not gated by can:{$permission}");
    }
});
