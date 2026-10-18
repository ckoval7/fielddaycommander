<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Dashboard;
use App\Models\User;

describe('Dashboard Model', function () {
    it('can create a dashboard with valid data', function () {
        $user = User::factory()->create();
        $dashboard = Dashboard::factory()->create([
            'user_id' => $user->id,
        ]);

        expect($dashboard)->toBeInstanceOf(Dashboard::class)
            ->and($dashboard->user_id)->toBe($user->id)
            ->and($dashboard->config)->toBeArray();
    });

    it('belongs to a user', function () {
        $user = User::factory()->create();
        $dashboard = Dashboard::factory()->create([
            'user_id' => $user->id,
        ]);

        expect($dashboard->user)->toBeInstanceOf(User::class)
            ->and($dashboard->user->id)->toBe($user->id);
    });

    it('casts config to array', function () {
        $dashboard = Dashboard::factory()->create();

        expect($dashboard->config)->toBeArray();
    });

    it('casts is_default to boolean', function () {
        $dashboard = Dashboard::factory()->create(['is_default' => true]);

        expect($dashboard->is_default)->toBeBool()
            ->and($dashboard->is_default)->toBeTrue();
    });
});

describe('Dashboard Scopes', function () {
    it('can scope to default dashboards', function () {
        $user = User::factory()->create();
        Dashboard::factory()->create(['user_id' => $user->id, 'is_default' => false]);
        $defaultDashboard = Dashboard::factory()->create(['user_id' => $user->id, 'is_default' => true]);

        $result = Dashboard::default()->get();

        expect($result)->toHaveCount(1)
            ->and($result->first()->id)->toBe($defaultDashboard->id);
    });

    it('can scope to dashboards for a specific user', function () {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $dashboard1 = Dashboard::factory()->create(['user_id' => $user1->id]);
        Dashboard::factory()->create(['user_id' => $user2->id]);

        $result = Dashboard::forUser($user1)->get();

        expect($result)->toHaveCount(1)
            ->and($result->first()->id)->toBe($dashboard1->id);
    });
});

describe('Widget Helper Methods', function () {
    it('counts visible widgets correctly', function () {
        $dashboard = Dashboard::factory()->create();

        expect($dashboard->getVisibleWidgetCount())->toBe(6);
    });

    it('counts visible widgets excluding hidden ones', function () {
        $dashboard = Dashboard::factory()->withHiddenWidgets()->create();

        expect($dashboard->getVisibleWidgetCount())->toBe(4); // 6 total - 2 hidden
    });

    it('returns zero for empty dashboard', function () {
        $dashboard = Dashboard::factory()->empty()->create();

        expect($dashboard->getVisibleWidgetCount())->toBe(0);
    });
});

describe('Factory States', function () {
    it('can create a default dashboard', function () {
        $dashboard = Dashboard::factory()->default()->create();

        expect($dashboard->is_default)->toBeTrue();
    });

    it('can create a minimal dashboard', function () {
        $dashboard = Dashboard::factory()->minimal()->create();

        expect($dashboard->config)->toHaveCount(2);
    });

    it('can create a TV dashboard', function () {
        $dashboard = Dashboard::factory()->tv()->create();

        expect($dashboard->layout_type)->toBe('tv')
            ->and($dashboard->config)->toHaveCount(10);
    });

    it('can create a dashboard with hidden widgets', function () {
        $dashboard = Dashboard::factory()->withHiddenWidgets()->create();

        $hiddenCount = collect($dashboard->config)
            ->filter(fn ($widget) => ! ($widget['visible'] ?? true))
            ->count();

        expect($hiddenCount)->toBe(2);
    });

    it('can create an empty dashboard', function () {
        $dashboard = Dashboard::factory()->empty()->create();

        expect($dashboard->config)->toHaveCount(0);
    });
});

describe('User Relationships', function () {
    it('user can have multiple dashboards', function () {
        $user = User::factory()->create();
        Dashboard::factory()->count(3)->create(['user_id' => $user->id]);

        expect($user->dashboards)->toHaveCount(3);
    });

    it('user can have a default dashboard', function () {
        $user = User::factory()->create();
        Dashboard::factory()->create(['user_id' => $user->id, 'is_default' => false]);
        $defaultDashboard = Dashboard::factory()->create(['user_id' => $user->id, 'is_default' => true]);

        expect($user->defaultDashboard)->toBeInstanceOf(Dashboard::class)
            ->and($user->defaultDashboard->id)->toBe($defaultDashboard->id);
    });

    it('cascades delete when user is force deleted', function () {
        $user = User::factory()->create();
        $dashboard = Dashboard::factory()->create(['user_id' => $user->id]);

        $dashboardId = $dashboard->id;
        $user->forceDelete(); // Use forceDelete because User has SoftDeletes

        expect(Dashboard::find($dashboardId))->toBeNull();
    });
});
