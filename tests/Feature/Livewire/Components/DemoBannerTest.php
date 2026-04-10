<?php

use App\Livewire\Components\DemoBanner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
