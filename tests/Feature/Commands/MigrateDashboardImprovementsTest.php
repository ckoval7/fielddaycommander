<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Dashboard;

/**
 * @return list<array<string, mixed>>
 */
function legacyDashboardWidgets(): array
{
    return [
        ['id' => 'w-list', 'type' => 'list_widget', 'config' => ['list_type' => 'recent_contacts'], 'order' => 0],
        ['id' => 'w-feed', 'type' => 'feed', 'config' => ['feed_type' => 'all_activity'], 'order' => 1],
        ['id' => 'w-chart', 'type' => 'chart', 'config' => ['chart_type' => 'bar', 'time_range' => 'event'], 'order' => 2],
        ['id' => 'w-score', 'type' => 'stat_card', 'config' => ['metric' => 'total_score'], 'order' => 3],
        ['id' => 'w-timer', 'type' => 'timer', 'config' => [], 'order' => 4],
        ['id' => 'w-rate', 'type' => 'stat_card', 'config' => ['metric' => 'qso_per_hour', 'show_comparison' => false, 'comparison_interval' => '4h'], 'order' => 5],
    ];
}

test('fails when there are no dashboards to migrate', function () {
    $this->artisan('dashboard:migrate-improvements')
        ->expectsOutput('No dashboards found to migrate.')
        ->assertFailed();
});

test('migrates widget configs, removes stale feeds and reorders widgets', function () {
    $dashboard = Dashboard::factory()->create(['config' => legacyDashboardWidgets()]);

    $this->artisan('dashboard:migrate-improvements')->assertSuccessful();

    $widgets = $dashboard->refresh()->config;

    expect(collect($widgets)->pluck('id')->all())
        ->toBe(['w-timer', 'w-rate', 'w-score', 'w-chart', 'w-list'])
        ->and(collect($widgets)->pluck('order')->all())->toBe([0, 1, 2, 3, 4]);

    $byId = collect($widgets)->keyBy('id');

    expect($byId['w-chart']['config'])->toBe(['chart_type' => 'line', 'time_range' => 'last_12_hours'])
        ->and($byId['w-score']['config'])->toBe([
            'metric' => 'total_score',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ])
        ->and($byId['w-rate']['config'])->toBe([
            'metric' => 'qso_per_hour',
            'show_comparison' => false,
            'comparison_interval' => '4h',
        ]);
});

test('keeps feed widgets that are not showing stale data', function () {
    $dashboard = Dashboard::factory()->create(['config' => [
        ['id' => 'w-feed', 'type' => 'activity_feed', 'config' => ['feed_type' => 'bulletins'], 'order' => 0],
        ['id' => 'w-chart', 'type' => 'chart', 'config' => ['chart_type' => 'bar'], 'order' => 1],
    ]]);

    $this->artisan('dashboard:migrate-improvements')->assertSuccessful();

    expect(collect($dashboard->refresh()->config)->pluck('id')->all())->toContain('w-feed');
});

test('migrates dashboards that wrap widgets in a widgets key and preserves other keys', function () {
    $dashboard = Dashboard::factory()->create(['config' => [
        'columns' => 3,
        'widgets' => [
            ['id' => 'w-chart', 'type' => 'chart', 'config' => ['chart_type' => 'bar', 'time_range' => 'all_time'], 'order' => 0],
        ],
    ]]);

    $this->artisan('dashboard:migrate-improvements')->assertSuccessful();

    $config = $dashboard->refresh()->config;

    expect($config['columns'])->toBe(3)
        ->and($config['widgets'][0]['config'])->toBe(['chart_type' => 'line', 'time_range' => 'last_12_hours']);
});

test('handles stat cards without a metric', function () {
    $dashboard = Dashboard::factory()->create(['config' => [
        ['id' => 'w-stat', 'type' => 'stat_card', 'config' => [], 'order' => 0],
    ]]);

    $this->artisan('dashboard:migrate-improvements')->assertSuccessful();

    expect($dashboard->refresh()->config[0]['config'])->toBe([
        'show_comparison' => true,
        'comparison_interval' => '1h',
    ]);
});

test('dry run reports changes without saving them', function () {
    $dashboard = Dashboard::factory()->create(['config' => legacyDashboardWidgets()]);

    $this->artisan('dashboard:migrate-improvements', ['--dry-run' => true])
        ->expectsOutput('DRY RUN MODE - No changes will be saved')
        ->expectsTable(['Metric', 'Count'], [['Dashboards Updated', 1], ['Widgets Updated', 3]])
        ->assertSuccessful();

    expect($dashboard->refresh()->config)->toBe(legacyDashboardWidgets());
});

test('only migrates dashboards for the given user', function () {
    $target = Dashboard::factory()->create(['config' => legacyDashboardWidgets()]);
    $other = Dashboard::factory()->create(['config' => legacyDashboardWidgets()]);

    $this->artisan('dashboard:migrate-improvements', ['--user' => $target->user_id])
        ->assertSuccessful();

    expect($target->refresh()->config)->not->toBe(legacyDashboardWidgets())
        ->and($other->refresh()->config)->toBe(legacyDashboardWidgets());
});

test('skips dashboards without widgets', function () {
    $dashboard = Dashboard::factory()->empty()->create();

    $this->artisan('dashboard:migrate-improvements')
        ->expectsOutputToContain('No widgets found, skipping')
        ->expectsTable(['Metric', 'Count'], [['Dashboards Updated', 0], ['Widgets Updated', 0]])
        ->assertSuccessful();

    expect($dashboard->refresh()->config)->toBe([]);
});

test('is idempotent when run a second time', function () {
    $dashboard = Dashboard::factory()->create(['config' => legacyDashboardWidgets()]);

    $this->artisan('dashboard:migrate-improvements')->assertSuccessful();
    $afterFirstRun = $dashboard->refresh()->config;
    $updatedAt = $dashboard->updated_at;

    $this->travel(5)->minutes();

    $this->artisan('dashboard:migrate-improvements')
        ->expectsOutputToContain('No changes needed')
        ->expectsTable(['Metric', 'Count'], [['Dashboards Updated', 0], ['Widgets Updated', 0]])
        ->assertSuccessful();

    $dashboard->refresh();

    expect($dashboard->config)->toBe($afterFirstRun)
        ->and($dashboard->updated_at->equalTo($updatedAt))->toBeTrue();
});
