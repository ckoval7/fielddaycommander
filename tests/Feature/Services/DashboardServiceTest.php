<?php

use App\Models\Dashboard;
use App\Models\User;
use App\Services\DashboardService;

beforeEach(function () {
    $this->service = new DashboardService;
    $this->user = User::factory()->create();
});

// ── createDashboard ──────────────────────────────────────────────────

test('createDashboard creates a dashboard with default user layout when no config provided', function () {
    $dashboard = $this->service->createDashboard($this->user, 'Test Dashboard');

    expect($dashboard)
        ->toBeInstanceOf(Dashboard::class)
        ->title->toBe('Test Dashboard')
        ->user_id->toBe($this->user->id)
        ->layout_type->toBe('grid')
        ->config->toBeArray()
        ->config->not->toBeEmpty();

    $expectedConfig = config('dashboard.default_dashboards.user')['widgets'];
    expect($dashboard->config)->toBe($expectedConfig);
});

test('createDashboard accepts custom description', function () {
    $dashboard = $this->service->createDashboard(
        $this->user,
        'My Board',
        'A custom description',
    );

    expect($dashboard->description)->toBe('A custom description');
});

test('createDashboard accepts custom valid config', function () {
    $config = [
        [
            'id' => 'w1',
            'type' => 'stat_card',
            'config' => ['metric' => 'total_score'],
            'order' => 1,
            'visible' => true,
        ],
    ];

    $dashboard = $this->service->createDashboard(
        $this->user,
        'Custom',
        null,
        $config,
    );

    expect($dashboard->config)->toBe($config);
});

test('createDashboard sets first dashboard as default', function () {
    $first = $this->service->createDashboard($this->user, 'First');

    expect($first->is_default)->toBeTrue();
});

test('createDashboard does not set subsequent dashboards as default', function () {
    $this->service->createDashboard($this->user, 'First');
    $second = $this->service->createDashboard($this->user, 'Second');

    expect($second->is_default)->toBeFalse();
});

test('createDashboard throws OverflowException when max dashboards reached', function () {
    Dashboard::factory()
        ->count(config('dashboard.max_dashboards_per_user'))
        ->for($this->user)
        ->create();

    $this->service->createDashboard($this->user, 'One Too Many');
})->throws(\OverflowException::class);

test('createDashboard throws InvalidArgumentException for invalid config', function () {
    $invalidConfig = [
        ['id' => 'w1', 'type' => 'nonexistent_widget_type'],
    ];

    $this->service->createDashboard($this->user, 'Bad Config', null, $invalidConfig);
})->throws(\InvalidArgumentException::class);

test('createDashboard throws OverflowException when config exceeds max widgets', function () {
    $widgets = [];
    $max = config('dashboard.max_widgets_per_dashboard');

    for ($i = 1; $i <= $max + 1; $i++) {
        $widgets[] = [
            'id' => "widget-{$i}",
            'type' => 'stat_card',
            'config' => ['metric' => 'total_score'],
            'order' => $i,
            'visible' => true,
        ];
    }

    $this->service->createDashboard($this->user, 'Too Many Widgets', null, $widgets);
})->throws(\OverflowException::class);

// ── updateDashboard ──────────────────────────────────────────────────

test('updateDashboard updates title', function () {
    $dashboard = Dashboard::factory()->for($this->user)->create(['title' => 'Old Title']);

    $updated = $this->service->updateDashboard($dashboard, ['title' => 'New Title']);

    expect($updated->title)->toBe('New Title');
});

test('updateDashboard updates description', function () {
    $dashboard = Dashboard::factory()->for($this->user)->create();

    $updated = $this->service->updateDashboard($dashboard, ['description' => 'Updated desc']);

    expect($updated->description)->toBe('Updated desc');
});

test('updateDashboard validates config before saving', function () {
    $dashboard = Dashboard::factory()->for($this->user)->create();

    $this->service->updateDashboard($dashboard, [
        'config' => [['id' => 'w1', 'type' => 'completely_fake']],
    ]);
})->throws(\InvalidArgumentException::class);

test('updateDashboard saves valid config', function () {
    $dashboard = Dashboard::factory()->for($this->user)->create();

    $newConfig = [
        [
            'id' => 'w1',
            'type' => 'timer',
            'config' => ['timer_type' => 'event_countdown'],
            'order' => 1,
            'visible' => true,
        ],
    ];

    $updated = $this->service->updateDashboard($dashboard, ['config' => $newConfig]);

    expect($updated->config)->toBe($newConfig);
});

test('updateDashboard throws OverflowException when config exceeds max widgets', function () {
    $dashboard = Dashboard::factory()->for($this->user)->create();
    $max = config('dashboard.max_widgets_per_dashboard');

    $widgets = [];
    for ($i = 1; $i <= $max + 1; $i++) {
        $widgets[] = [
            'id' => "widget-{$i}",
            'type' => 'stat_card',
            'config' => ['metric' => 'total_score'],
            'order' => $i,
            'visible' => true,
        ];
    }

    $this->service->updateDashboard($dashboard, ['config' => $widgets]);
})->throws(\OverflowException::class);

// ── deleteDashboard ──────────────────────────────────────────────────

test('deleteDashboard deletes a non-default dashboard', function () {
    $default = Dashboard::factory()->default()->for($this->user)->create();
    $other = Dashboard::factory()->for($this->user)->create();

    $result = $this->service->deleteDashboard($other);

    expect($result)->toBeTrue();
    $this->assertDatabaseMissing('dashboards', ['id' => $other->id]);
    $this->assertDatabaseHas('dashboards', ['id' => $default->id]);
});

test('deleteDashboard throws LogicException when deleting the only dashboard', function () {
    $dashboard = Dashboard::factory()->for($this->user)->create();

    $this->service->deleteDashboard($dashboard);
})->throws(\LogicException::class, 'Cannot delete the only dashboard');

test('deleteDashboard throws LogicException when deleting the default dashboard', function () {
    Dashboard::factory()->default()->for($this->user)->create();
    $default = Dashboard::factory()->default()->for($this->user)->create();

    $this->service->deleteDashboard($default);
})->throws(\LogicException::class, 'Cannot delete the default dashboard');

// ── duplicateDashboard ───────────────────────────────────────────────

test('duplicateDashboard creates a copy with new title', function () {
    $original = Dashboard::factory()->for($this->user)->create([
        'title' => 'Original',
        'description' => 'Original desc',
    ]);

    $copy = $this->service->duplicateDashboard($original, 'Copy of Original');

    expect($copy)
        ->title->toBe('Copy of Original')
        ->description->toBe('Original desc')
        ->config->toBe($original->config)
        ->is_default->toBeFalse()
        ->layout_type->toBe($original->layout_type)
        ->user_id->toBe($this->user->id);

    expect($copy->id)->not->toBe($original->id);
});

test('duplicateDashboard throws OverflowException when max dashboards reached', function () {
    $dashboards = Dashboard::factory()
        ->count(config('dashboard.max_dashboards_per_user'))
        ->for($this->user)
        ->create();

    $this->service->duplicateDashboard($dashboards->first(), 'Too Many');
})->throws(\OverflowException::class);

// ── getDefaultDashboard ──────────────────────────────────────────────

test('getDefaultDashboard returns existing default', function () {
    $default = Dashboard::factory()->default()->for($this->user)->create();
    Dashboard::factory()->for($this->user)->create();

    $result = $this->service->getDefaultDashboard($this->user);

    expect($result->id)->toBe($default->id);
});

test('getDefaultDashboard promotes first dashboard when no default set', function () {
    $first = Dashboard::factory()->for($this->user)->create(['is_default' => false]);
    Dashboard::factory()->for($this->user)->create(['is_default' => false]);

    $result = $this->service->getDefaultDashboard($this->user);

    expect($result->id)->toBe($first->id);
    expect($result->is_default)->toBeTrue();
});

test('getDefaultDashboard creates a new dashboard for user with none', function () {
    expect($this->user->dashboards()->count())->toBe(0);

    $result = $this->service->getDefaultDashboard($this->user);

    expect($result)
        ->toBeInstanceOf(Dashboard::class)
        ->title->toBe('My Dashboard')
        ->is_default->toBeTrue()
        ->user_id->toBe($this->user->id);

    expect($result->config)->toBe(config('dashboard.default_dashboards.user')['widgets']);
});

// ── setAsDefault ─────────────────────────────────────────────────────

test('setAsDefault marks a dashboard as default', function () {
    $first = Dashboard::factory()->default()->for($this->user)->create();
    $second = Dashboard::factory()->for($this->user)->create();

    $this->service->setAsDefault($second);

    expect($first->fresh()->is_default)->toBeFalse();
    expect($second->fresh()->is_default)->toBeTrue();
});

test('setAsDefault unsets previous default for that user only', function () {
    $otherUser = User::factory()->create();
    $otherDefault = Dashboard::factory()->default()->for($otherUser)->create();

    $first = Dashboard::factory()->default()->for($this->user)->create();
    $second = Dashboard::factory()->for($this->user)->create();

    $this->service->setAsDefault($second);

    expect($otherDefault->fresh()->is_default)->toBeTrue();
    expect($first->fresh()->is_default)->toBeFalse();
    expect($second->fresh()->is_default)->toBeTrue();
});

// ── validateConfig ───────────────────────────────────────────────────

test('validateConfig returns true for valid config', function () {
    $config = [
        [
            'id' => 'w1',
            'type' => 'stat_card',
            'config' => ['metric' => 'total_score'],
            'order' => 1,
            'visible' => true,
        ],
        [
            'id' => 'w2',
            'type' => 'chart',
            'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
            'order' => 2,
            'visible' => false,
        ],
    ];

    expect($this->service->validateConfig($config))->toBeTrue();
});

test('validateConfig returns true for empty config', function () {
    expect($this->service->validateConfig([]))->toBeTrue();
});

test('validateConfig returns false when widget is not an array', function () {
    expect($this->service->validateConfig(['not-an-array']))->toBeFalse();
});

test('validateConfig returns false when widget missing id', function () {
    $config = [['type' => 'stat_card']];

    expect($this->service->validateConfig($config))->toBeFalse();
});

test('validateConfig returns false when widget missing type', function () {
    $config = [['id' => 'w1']];

    expect($this->service->validateConfig($config))->toBeFalse();
});

test('validateConfig returns false for invalid widget type', function () {
    $config = [['id' => 'w1', 'type' => 'unicorn_widget']];

    expect($this->service->validateConfig($config))->toBeFalse();
});

test('validateConfig returns false when config field is not array', function () {
    $config = [['id' => 'w1', 'type' => 'stat_card', 'config' => 'string']];

    expect($this->service->validateConfig($config))->toBeFalse();
});

test('validateConfig returns false when order is not integer', function () {
    $config = [['id' => 'w1', 'type' => 'stat_card', 'order' => 'first']];

    expect($this->service->validateConfig($config))->toBeFalse();
});

test('validateConfig returns false when visible is not boolean', function () {
    $config = [['id' => 'w1', 'type' => 'stat_card', 'visible' => 1]];

    expect($this->service->validateConfig($config))->toBeFalse();
});

test('validateConfig accepts all valid widget types', function () {
    $validTypes = array_keys(config('dashboard.widget_types'));

    foreach ($validTypes as $index => $type) {
        $config = [['id' => "w{$index}", 'type' => $type]];

        expect($this->service->validateConfig($config))->toBeTrue();
    }
});

// ── applyDefaultLayout ───────────────────────────────────────────────

test('applyDefaultLayout returns guest layout', function () {
    $layout = $this->service->applyDefaultLayout('guest');

    expect($layout)->toBe(config('dashboard.default_dashboards.guest')['widgets']);
});

test('applyDefaultLayout returns user layout', function () {
    $layout = $this->service->applyDefaultLayout('user');

    expect($layout)->toBe(config('dashboard.default_dashboards.user')['widgets']);
});

test('applyDefaultLayout returns tv layout', function () {
    $layout = $this->service->applyDefaultLayout('tv');

    expect($layout)->toBe(config('dashboard.default_dashboards.tv')['widgets']);
});

test('applyDefaultLayout falls back to user layout for unknown type', function () {
    $layout = $this->service->applyDefaultLayout('nonexistent');

    expect($layout)->toBe(config('dashboard.default_dashboards.user')['widgets']);
});
