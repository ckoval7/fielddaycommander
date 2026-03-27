<?php

use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

test('index requires authentication', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('index loads user default dashboard', function () {
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
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard.alt'));

    $response->assertStatus(200);
    $response->assertViewIs('dashboard.default');
});

test('only creates one default dashboard per user', function () {
    $user = User::factory()->create();

    // First request creates dashboard
    $this->actingAs($user)->get(route('dashboard'));
    expect(Dashboard::forUser($user)->count())->toBe(1);

    // Second request should not create another
    $this->actingAs($user)->get(route('dashboard'));
    expect(Dashboard::forUser($user)->count())->toBe(1);
});

test('dashboard widgets config matches user default', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);

    $widgets = $response->viewData('widgets');
    $expectedWidgets = config('dashboard.default_dashboards.user.widgets');

    expect($widgets->toArray())->toBe($expectedWidgets);
});
