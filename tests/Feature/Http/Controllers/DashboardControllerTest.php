<?php

use App\Models\Dashboard;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mark system as set up
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

/**
 * Create an active event so the dashboard controller shows the widget grid.
 */
function createActiveEvent(): Event
{
    return Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
}

test('index requires authentication', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('index loads user default dashboard', function () {
    createActiveEvent();
    $user = User::factory()->create();
    $dashboard = Dashboard::factory()->create([
        'user_id' => $user->id,
        'is_default' => true,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertViewIs('dashboard.default');
    $response->assertViewHas('dashboard', $dashboard);
    $response->assertViewHas('widgets', collect($dashboard->config));
});

test('index creates default dashboard for new user', function () {
    createActiveEvent();
    $user = User::factory()->create();

    expect(Dashboard::forUser($user)->count())->toBe(0);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertViewIs('dashboard.default');

    // Verify dashboard was created
    expect(Dashboard::forUser($user)->count())->toBe(1);

    $dashboard = Dashboard::forUser($user)->first();
    expect($dashboard->is_default)->toBeTrue();
    expect($dashboard->title)->toBe(config('dashboard.default_dashboards.user.title'));
    expect($dashboard->config)->toBe(config('dashboard.default_dashboards.user.widgets'));
});

test('tv dashboard is publicly accessible', function () {
    $response = $this->get(route('dashboard.tv'));

    $response->assertStatus(200);
    $response->assertViewIs('dashboard.tv');
});

test('tv dashboard loads config from file', function () {
    $response = $this->get(route('dashboard.tv'));

    $response->assertStatus(200);
    $response->assertViewHas('title', config('dashboard.default_dashboards.tv.title'));
    $response->assertViewHas('widgets', collect(config('dashboard.default_dashboards.tv.widgets')));
    $response->assertViewHas('layout_type', 'tv');
});

test('tv dashboard handles kiosk query parameter', function () {
    $response = $this->get(route('dashboard.tv', ['kiosk' => 1]));

    $response->assertStatus(200);
    $response->assertViewHas('kiosk', true);
});

test('tv dashboard defaults kiosk to false', function () {
    $response = $this->get(route('dashboard.tv'));

    $response->assertStatus(200);
    $response->assertViewHas('kiosk', false);
});

test('dashboard.alt route also works', function () {
    createActiveEvent();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard.alt'));

    $response->assertStatus(200);
    $response->assertViewIs('dashboard.default');
});

test('shows get-ready view when upcoming event exists', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create([
        'start_time' => appNow()->addDays(7),
        'end_time' => appNow()->addDays(8),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertViewIs('dashboard.get-ready');
});

test('get-ready checklist hides routes for unprivileged users', function () {
    Permission::create(['name' => 'view-events']);
    Permission::create(['name' => 'manage-event-equipment']);
    Permission::create(['name' => 'view-all-equipment']);
    Permission::create(['name' => 'view-stations']);
    Permission::create(['name' => 'manage-shifts']);
    Permission::create(['name' => 'manage-own-equipment']);

    $user = User::factory()->create();
    Event::factory()->create([
        'start_time' => appNow()->addDays(7),
        'end_time' => appNow()->addDays(8),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);
    $checklist = $response->viewData('checklist');

    // Items accessible to all users (no permission gating)
    $alwaysVisible = ['W1AW bulletin schedule set up', 'Equipment inventoried'];

    // Items gated by permissions should have null routes for unprivileged users
    $gatedItems = array_filter($checklist, fn ($item) => ! in_array($item['label'], $alwaysVisible));
    foreach ($gatedItems as $item) {
        expect($item['route'])->toBeNull("Button for '{$item['label']}' should be hidden for unprivileged users");
    }

    // Equipment and W1AW bulletin schedule are accessible to all authenticated users
    foreach ($alwaysVisible as $label) {
        $item = collect($checklist)->firstWhere('label', $label);
        expect($item['route'])->not->toBeNull("Button for '{$label}' should be visible for all users");
    }
});

test('get-ready checklist shows routes for privileged users', function () {
    Permission::create(['name' => 'view-events']);
    Permission::create(['name' => 'manage-event-equipment']);
    Permission::create(['name' => 'view-all-equipment']);
    Permission::create(['name' => 'view-stations']);
    Permission::create(['name' => 'manage-shifts']);

    $user = User::factory()->create();
    $user->givePermissionTo(['view-events', 'manage-event-equipment', 'view-stations', 'manage-shifts']);
    Event::factory()->create([
        'start_time' => appNow()->addDays(7),
        'end_time' => appNow()->addDays(8),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);
    $checklist = $response->viewData('checklist');

    foreach ($checklist as $item) {
        expect($item['route'])->not->toBeNull("Button for '{$item['label']}' should be visible for privileged users");
    }
});

test('only creates one default dashboard per user', function () {
    createActiveEvent();
    $user = User::factory()->create();

    // First request creates dashboard
    $this->actingAs($user)->get(route('dashboard'));
    expect(Dashboard::forUser($user)->count())->toBe(1);

    // Second request should not create another
    $this->actingAs($user)->get(route('dashboard'));
    expect(Dashboard::forUser($user)->count())->toBe(1);
});

test('dashboard widgets config matches user default', function () {
    createActiveEvent();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);

    $widgets = $response->viewData('widgets');
    $expectedWidgets = config('dashboard.default_dashboards.user.widgets');

    expect($widgets->toArray())->toBe($expectedWidgets);
});
