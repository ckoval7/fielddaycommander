<?php

use App\Livewire\Admin\DemoAnalytics;
use App\Models\DemoSession;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    config(['demo.enabled' => true]);

    // Seed permissions/roles if not already present
    $this->artisan('db:seed', ['--class' => 'PermissionSeeder', '--no-interaction' => true]);
    $this->artisan('db:seed', ['--class' => 'RoleSeeder', '--no-interaction' => true]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('System Administrator');
});

it('renders the analytics dashboard for system admins', function () {
    Livewire::actingAs($this->admin)
        ->test(DemoAnalytics::class)
        ->assertStatus(200)
        ->assertSee('Demo Analytics');
});

it('denies access to non-admin users', function () {
    $operator = User::factory()->create();
    $operator->assignRole('Operator');

    Livewire::actingAs($operator)
        ->test(DemoAnalytics::class)
        ->assertForbidden();
});

it('shows overview metrics', function () {
    $hash1 = hash('sha256', 'visitor1');
    $hash2 = hash('sha256', 'visitor2');

    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => $hash1,
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subHours(2),
        'last_seen_at' => now()->subHour(),
        'total_page_views' => 15,
        'total_actions' => 5,
        'expires_at' => now()->addHours(22),
    ]);

    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'system_admin',
        'visitor_hash' => $hash1,
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subHour(),
        'last_seen_at' => now(),
        'total_page_views' => 8,
        'total_actions' => 2,
        'expires_at' => now()->addHours(23),
    ]);

    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => $hash2,
        'user_agent' => 'Test Mobile',
        'device_type' => 'mobile',
        'provisioned_at' => now()->subMinutes(30),
        'last_seen_at' => now()->subMinutes(25),
        'total_page_views' => 1,
        'total_actions' => 0,
        'expires_at' => now()->addHours(24),
    ]);

    $component = Livewire::actingAs($this->admin)
        ->test(DemoAnalytics::class);

    $component->assertSee('3');
});

it('filters by date range', function () {
    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'old'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subDays(10),
        'last_seen_at' => now()->subDays(10),
        'total_page_views' => 5,
        'expires_at' => now()->subDays(9),
    ]);

    DemoSession::create([
        'session_uuid' => fake()->uuid(),
        'role' => 'system_admin',
        'visitor_hash' => hash('sha256', 'recent'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now()->subDay(),
        'last_seen_at' => now(),
        'total_page_views' => 10,
        'expires_at' => now()->addHours(23),
    ]);

    $component = Livewire::actingAs($this->admin)
        ->test(DemoAnalytics::class)
        ->set('dateRange', '7d');

    $component->assertSee('1');
});
