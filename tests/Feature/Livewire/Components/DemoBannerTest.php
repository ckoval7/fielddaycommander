<?php

use App\Livewire\Components\DemoBanner;
use App\Models\DemoSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('demo.enabled', true);
    Config::set('demo.ttl_hours', 24);
    Cache::flush();

    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true', 'updated_at' => now(), 'created_at' => now()]
    );
});

test('banner shows demo mode message when enabled', function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'demo_provisioned_at'],
        ['value' => now()->toIso8601String(), 'updated_at' => now(), 'created_at' => now()]
    );
    Cache::flush();

    Livewire::test(DemoBanner::class)
        ->assertSee('demo mode')
        ->assertSee('Expires in');
});

test('banner shows time remaining based on provisioned_at', function () {
    $provisionedAt = now()->subHours(2);
    DB::table('system_config')->updateOrInsert(
        ['key' => 'demo_provisioned_at'],
        ['value' => $provisionedAt->toIso8601String(), 'updated_at' => now(), 'created_at' => now()]
    );
    Cache::flush();

    Livewire::test(DemoBanner::class)
        ->assertSet('isVisible', true)
        ->assertSet('expiresAt', fn ($value) => $value instanceof Carbon && $value->diffInHours(now(), true) >= 21);
});

test('banner is hidden when demo mode is disabled', function () {
    Config::set('demo.enabled', false);

    Livewire::test(DemoBanner::class)
        ->assertDontSee('demo mode');
});

test('banner contains reset form', function () {
    DB::table('system_config')->updateOrInsert(
        ['key' => 'demo_provisioned_at'],
        ['value' => now()->toIso8601String(), 'updated_at' => now(), 'created_at' => now()]
    );
    Cache::flush();

    Livewire::test(DemoBanner::class)
        ->assertSeeHtml('demo/reset')
        ->assertSee('Start over');
});

test('banner offers an invite link for the visitor\'s sandbox', function () {
    Config::set('demo.max_users_per_session', 4);

    DB::table('system_config')->updateOrInsert(
        ['key' => 'demo_provisioned_at'],
        ['value' => now()->toIso8601String(), 'updated_at' => now(), 'created_at' => now()]
    );
    Cache::flush();

    $demoSession = DemoSession::create([
        'session_uuid' => (string) Str::uuid(),
        'role' => 'operator',
        'event_type' => 'FD',
        'participant_count' => 2,
        'visitor_hash' => str_repeat('a', 64),
        'user_agent' => 'Pest',
        'provisioned_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    Livewire::withCookies(['demo_session' => $demoSession->session_uuid.'|operator'])
        ->test(DemoBanner::class)
        ->assertSet('inviteUrl', route('demo.join', $demoSession->fresh()->invite_token))
        ->assertSee('Invite')
        ->assertSee('2 of 4 spots taken');
});

test('banner hides the invite link when invitations are turned off', function () {
    Config::set('demo.max_users_per_session', 1);

    DB::table('system_config')->updateOrInsert(
        ['key' => 'demo_provisioned_at'],
        ['value' => now()->toIso8601String(), 'updated_at' => now(), 'created_at' => now()]
    );
    Cache::flush();

    Livewire::withCookies(['demo_session' => Str::uuid().'|operator'])
        ->test(DemoBanner::class)
        ->assertSet('inviteUrl', null)
        ->assertDontSee('Invite others');
});
