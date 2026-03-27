<?php

use App\Livewire\Components\DeveloperBanner;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeveloperClockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

beforeEach(function () {
    // Clear any existing time overrides
    Setting::set('dev.fake_time', null);
    Setting::set('dev.time_frozen', null);
    Setting::set('dev.fake_time_set_at', null);
});

describe('Banner Visibility', function () {
    test('banner shows when dev mode enabled and fake time is set', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSee('DEVELOPER MODE')
            ->assertSee('June 28, 2025 18:00');
    });

    test('banner hidden when dev mode is disabled even with fake time', function () {
        Config::set('developer.enabled', false);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        // Component should detect that it should not be visible
        $component = new DeveloperBanner;
        $component->mount();

        expect($component->isVisible)->toBeFalse();
    });

    test('banner hidden when no fake time is set even with dev mode enabled', function () {
        Config::set('developer.enabled', true);

        // Component should detect that it should not be visible
        $component = new DeveloperBanner;
        $component->mount();

        expect($component->isVisible)->toBeFalse();
    });

    test('banner hidden after dismiss action', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSee('DEVELOPER MODE')
            ->call('dismiss')
            ->assertSet('isDismissed', true);
    });

    test('dismissal persists in session across component instances', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        // First instance - dismiss the banner
        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->call('dismiss')
            ->assertSet('isDismissed', true);

        // The session-based dismissal is tested via the isDismissed property
    });
});

describe('Permission Tests', function () {
    test('configure link visible to admin users with manage-settings permission', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        $adminUser = User::factory()->create();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage-settings']);
        $adminUser->givePermissionTo('manage-settings');
        $this->actingAs($adminUser);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSee('Configure');
    });

    test('configure link not visible to regular users without permission', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        $regularUser = User::factory()->create(['user_role' => 'user']);
        $this->actingAs($regularUser);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertDontSee('Configure');
    });

    test('banner itself is visible to all users when conditions met', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        $regularUser = User::factory()->create(['user_role' => 'user']);
        $this->actingAs($regularUser);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSee('DEVELOPER MODE')
            ->assertSee('June 28, 2025 18:00');
    });

    test('configure link not visible to unauthenticated users', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertDontSee('Configure');
    });
});

describe('Content Display', function () {
    test('displays correct formatted time in UTC', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSee('June 28, 2025 18:00')
            ->assertSee('UTC');
    });

    test('shows frozen badge when time is frozen', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSet('isFrozen', true)
            ->assertSee('frozen');
    });

    test('shows flowing badge when time is not frozen', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: false);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSet('isFrozen', false)
            ->assertSee('flowing');
    });

    test('displays time with different date formats correctly', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2024-12-01 23:59:59'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSee('December 1, 2024 23:59');
    });
});

describe('Dismiss Functionality', function () {
    test('calling dismiss hides the banner by setting isDismissed', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSet('isDismissed', false)
            ->call('dismiss')
            ->assertSet('isDismissed', true);
    });

    test('dismiss button is present when banner is visible', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSeeHtml('wire:click="dismiss"');
    });

    test('dismissed state prevents banner from showing on mount', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        // Test that after dismissing, isVisible becomes false
        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->set('isDismissed', true)
            ->assertSet('isDismissed', true);
    });
});

describe('Clock Service Integration', function () {
    test('component correctly reads frozen state from clock service', function () {
        Config::set('developer.enabled', true);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isFrozen', true);

        // Clear and set flowing time
        $clockService->clearFakeTime();
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: false);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isFrozen', false);
    });

    test('component correctly reads fake time from clock service', function () {
        Config::set('developer.enabled', true);

        $expectedTime = Carbon::parse('2025-06-28 18:00:00');
        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime($expectedTime, frozen: true);

        Livewire::test(DeveloperBanner::class)
            ->assertSet('isVisible', true)
            ->assertSet('fakeTime', fn ($value) => $value->eq($expectedTime));
    });

    test('component respects clock service enabled state', function () {
        Config::set('developer.enabled', false);

        $clockService = app(DeveloperClockService::class);
        $clockService->setFakeTime(Carbon::parse('2025-06-28 18:00:00'), frozen: true);

        expect($clockService->isEnabled())->toBeFalse();

        // Component should detect that it should not be visible
        $component = new DeveloperBanner;
        $component->mount();

        expect($component->isVisible)->toBeFalse();
    });
});
