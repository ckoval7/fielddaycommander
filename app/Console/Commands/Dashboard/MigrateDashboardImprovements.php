<?php

namespace App\Console\Commands\Dashboard;

use App\Models\Dashboard;
use Illuminate\Console\Command;

class MigrateDashboardImprovements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dashboard:migrate-improvements
                            {--dry-run : Show what would be changed without making changes}
                            {--user= : Only migrate dashboards for specific user ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing dashboards to use the new dashboard improvements configuration';

    protected int $dashboardsUpdated = 0;

    protected int $widgetsUpdated = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->dashboardsUpdated = 0;
        $this->widgetsUpdated = 0;

        $this->info('🚀 Dashboard Improvements Migration');
        $this->newLine();

        $isDryRun = $this->option('dry-run');
        $userId = $this->option('user');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be saved');
            $this->newLine();
        }

        // Get dashboards to migrate
        $query = Dashboard::query();
        if ($userId) {
            $query->where('user_id', $userId);
        }

        $dashboards = $query->get();

        if ($dashboards->isEmpty()) {
            $this->error('No dashboards found to migrate.');

            return self::FAILURE;
        }

        $this->info("Found {$dashboards->count()} dashboard(s) to migrate");
        $this->newLine();

        // Process each dashboard
        foreach ($dashboards as $dashboard) {
            $this->migrateDashboard($dashboard, $isDryRun);
        }

        $this->newLine();
        $this->info('✅ Migration Complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Dashboards Updated', $this->dashboardsUpdated],
                ['Widgets Updated', $this->widgetsUpdated],
            ]
        );

        if ($isDryRun) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return self::SUCCESS;
    }

    protected function migrateDashboard(Dashboard $dashboard, bool $isDryRun): void
    {
        $this->line("📊 Migrating dashboard: {$dashboard->title} (ID: {$dashboard->id})");

        $config = $dashboard->config;
        $widgets = $config['widgets'] ?? $config ?? [];

        if (empty($widgets)) {
            $this->warn('  ⚠️  No widgets found, skipping');

            return;
        }

        [$updatedWidgets, $hasChanges] = $this->processWidgets($widgets);

        if ($hasChanges || count($updatedWidgets) !== count($widgets)) {
            $this->dashboardsUpdated++;
            $this->saveDashboardConfig($dashboard, $config, $updatedWidgets, $isDryRun);
            $this->info("  ✓ Updated {$dashboard->title}");
        } else {
            $this->line('  → No changes needed');
        }
    }

    /**
     * Process all widgets, migrating each and tracking changes.
     *
     * @return array{0: array, 1: bool} Tuple of [updatedWidgets, hasChanges]
     */
    protected function processWidgets(array $widgets): array
    {
        $newOrderMap = [
            'timer' => 0, 'stat_card_qso_rate' => 1, 'stat_card_total_score' => 2,
            'progress_bar' => 3, 'chart' => 4, 'list_widget_recent' => 5, 'list_widget_active' => 6,
        ];

        $updatedWidgets = [];
        $hasChanges = false;

        foreach ($widgets as $widget) {
            $updated = $this->migrateWidget($widget);

            if ($updated !== $widget) {
                $hasChanges = true;
                $this->widgetsUpdated++;
            }

            if ($updated !== null) {
                $updatedWidgets[] = $updated;
            }
        }

        usort($updatedWidgets, fn ($a, $b) => $this->getWidgetNewOrder($a, $newOrderMap) <=> $this->getWidgetNewOrder($b, $newOrderMap));

        foreach ($updatedWidgets as $index => $widget) {
            $updatedWidgets[$index]['order'] = $index;
        }

        return [$updatedWidgets, $hasChanges];
    }

    /**
     * Save the updated widget config to the dashboard.
     */
    protected function saveDashboardConfig(Dashboard $dashboard, array $config, array $updatedWidgets, bool $isDryRun): void
    {
        if ($isDryRun) {
            return;
        }

        if (isset($config['widgets'])) {
            $config['widgets'] = $updatedWidgets;
        } else {
            $config = $updatedWidgets;
        }

        $dashboard->update(['config' => $config]);
    }

    protected function migrateWidget(array $widget): ?array
    {
        $type = $widget['type'] ?? null;

        return match ($type) {
            'chart' => $this->migrateChartWidget($widget),
            'stat_card' => $this->migrateStatCardWidget($widget),
            'feed', 'activity_feed' => $this->migrateFeedWidget($widget),
            default => $widget,
        };
    }

    /**
     * Migrate a chart widget's configuration.
     */
    protected function migrateChartWidget(array $widget): array
    {
        $config = $widget['config'] ?? [];
        $changes = [];

        if (($config['chart_type'] ?? null) === 'bar') {
            $config['chart_type'] = 'line';
            $changes[] = 'chart_type: bar → line';
        }

        if (in_array($config['time_range'] ?? null, ['event', 'all_time'])) {
            $changes[] = 'time_range: '.$widget['config']['time_range'].' → last_12_hours';
            $config['time_range'] = 'last_12_hours';
        }

        if (! empty($changes)) {
            $widget['config'] = $config;
            $this->line('    • Chart: '.implode(', ', $changes));
        }

        return $widget;
    }

    /**
     * Migrate a stat card widget's configuration.
     */
    protected function migrateStatCardWidget(array $widget): array
    {
        $config = $widget['config'] ?? [];
        $changes = [];

        if (! isset($config['show_comparison'])) {
            $config['show_comparison'] = true;
            $changes[] = 'enabled comparison';
        }

        if (! isset($config['comparison_interval'])) {
            $config['comparison_interval'] = '1h';
            $changes[] = 'set comparison interval to 1h';
        }

        $metricMap = ['qso_count' => 'qso_count', 'total_score' => 'total_score'];

        if (isset($config['metric'], $metricMap[$config['metric']])) {
            $oldMetric = $config['metric'];
            $config['metric'] = $metricMap[$oldMetric];
            if ($oldMetric !== $config['metric']) {
                $changes[] = "metric: {$oldMetric} → {$config['metric']}";
            }
        }

        if (! empty($changes)) {
            $widget['config'] = $config;
            $this->line('    • Stat Card ('.($config['metric'] ?? 'no metric').'): '.implode(', ', $changes));
        }

        return $widget;
    }

    /**
     * Migrate a feed/activity_feed widget (remove if showing stale data).
     */
    protected function migrateFeedWidget(array $widget): ?array
    {
        $listType = $widget['config']['feed_type'] ?? null;

        if (in_array($listType, ['all_activity', 'contacts_only'])) {
            $this->warn('    ⨯ Removed Activity Feed widget (showing stale data)');

            return null;
        }

        return $widget;
    }

    protected function getWidgetNewOrder(array $widget, array $newOrderMap): int
    {
        $type = $widget['type'] ?? 'unknown';
        $config = $widget['config'] ?? [];

        // Map widget to order key
        $orderKey = match ($type) {
            'timer' => 'timer',
            'stat_card' => match ($config['metric'] ?? '') {
                'qso_per_hour', 'contacts_last_hour', 'avg_qso_rate_4h' => 'stat_card_qso_rate',
                'total_score' => 'stat_card_total_score',
                default => 'stat_card_other',
            },
            'progress_bar' => 'progress_bar',
            'chart' => 'chart',
            'list_widget' => match ($config['list_type'] ?? '') {
                'recent_contacts' => 'list_widget_recent',
                'active_stations' => 'list_widget_active',
                default => 'list_widget_other',
            },
            default => 'other',
        };

        return $newOrderMap[$orderKey] ?? 999;
    }
}
