<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Models\Contact;
use App\Services\EventContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Chart widget for visualizing QSO data with Chart.js.
 *
 * Supports multiple data sources (qsos_per_hour, qsos_per_band, qsos_per_mode)
 * and chart types (bar, line, pie). Uses Alpine.js to manage the Chart.js
 * instance on the client side with proper lifecycle management.
 *
 * @property string $size Widget size variant ('normal' or 'tv')
 * @property array $config Widget configuration including chart_type and data_source
 */
class Chart extends Component
{
    use IsWidget;

    /**
     * Supported chart types.
     *
     * @var array<string>
     */
    protected const CHART_TYPES = ['bar', 'line', 'pie', 'doughnut'];

    /**
     * Supported data sources.
     *
     * @var array<string>
     */
    protected const DATA_SOURCES = ['qsos_per_hour', 'qsos_per_band', 'qsos_per_mode'];

    /**
     * Supported time ranges, mapped to the lookback applied at query time.
     * `null` means "no lookback — use full event window".
     *
     * @var array<string, ?int>
     */
    protected const TIME_RANGE_HOURS = [
        'last_hour' => 1,
        'last_4_hours' => 4,
        'last_12_hours' => 12,
        'event' => null,
    ];

    /**
     * Human-readable suffix appended to chart titles per time range.
     *
     * @var array<string, string>
     */
    protected const TIME_RANGE_LABELS = [
        'last_hour' => 'Last Hour',
        'last_4_hours' => 'Last 4 Hours',
        'last_12_hours' => 'Last 12 Hours',
        'event' => 'Entire Event',
    ];

    /**
     * Cache duration in seconds for chart data.
     */
    protected const CACHE_TTL = 5;

    /**
     * Chart.js payload mirrored to the client on every render.
     *
     * Alpine watches this through $wire; data baked into x-data is never
     * re-read after the first render because Livewire's morph keeps Alpine
     * state, which left polled charts frozen at their page-load data.
     *
     * @var array<string, mixed>
     */
    public array $chartPayload = [];

    /**
     * Color palette for chart datasets.
     *
     * Uses oklch-based colors that work well with both light and dark themes.
     *
     * @var array<string>
     */
    protected const CHART_COLORS = [
        'rgba(59, 130, 246, 0.8)',   // blue
        'rgba(16, 185, 129, 0.8)',   // emerald
        'rgba(245, 158, 11, 0.8)',   // amber
        'rgba(239, 68, 68, 0.8)',    // red
        'rgba(139, 92, 246, 0.8)',   // violet
        'rgba(236, 72, 153, 0.8)',   // pink
        'rgba(6, 182, 212, 0.8)',    // cyan
        'rgba(251, 146, 60, 0.8)',   // orange
        'rgba(34, 197, 94, 0.8)',    // green
        'rgba(168, 85, 247, 0.8)',   // purple
    ];

    /**
     * Border color palette (slightly darker versions).
     *
     * @var array<string>
     */
    protected const CHART_BORDER_COLORS = [
        'rgba(59, 130, 246, 1)',
        'rgba(16, 185, 129, 1)',
        'rgba(245, 158, 11, 1)',
        'rgba(239, 68, 68, 1)',
        'rgba(139, 92, 246, 1)',
        'rgba(236, 72, 153, 1)',
        'rgba(6, 182, 212, 1)',
        'rgba(251, 146, 60, 1)',
        'rgba(34, 197, 94, 1)',
        'rgba(168, 85, 247, 1)',
    ];

    /**
     * Fetch chart data from the active event's contacts.
     *
     * Returns Chart.js-ready data structure with labels and datasets.
     * Results are cached for CACHE_TTL seconds using the widget cache key.
     *
     * @return array{labels: array<string>, datasets: array<array<string, mixed>>, chart_type: string, title: string, description: string}
     */
    public function getData(): array
    {
        return Cache::remember($this->cacheKey(), self::CACHE_TTL, function () {
            $dataSource = $this->config['data_source'] ?? 'qsos_per_hour';
            $chartType = $this->config['chart_type'] ?? 'bar';
            $timeRange = $this->config['time_range'] ?? 'event';

            if (! in_array($chartType, self::CHART_TYPES)) {
                $chartType = 'bar';
            }

            if (! in_array($dataSource, self::DATA_SOURCES)) {
                $dataSource = 'qsos_per_hour';
            }

            if (! array_key_exists($timeRange, self::TIME_RANGE_HOURS)) {
                $timeRange = 'event';
            }

            $rawData = match ($dataSource) {
                'qsos_per_hour' => $this->getQsosPerHour($timeRange),
                'qsos_per_band' => $this->getQsosPerBand($timeRange),
                'qsos_per_mode' => $this->getQsosPerMode($timeRange),
                default => $this->getQsosPerHour($timeRange),
            };

            $baseTitle = match ($dataSource) {
                'qsos_per_hour' => 'QSOs per Hour',
                'qsos_per_band' => 'QSOs per Band',
                'qsos_per_mode' => 'QSOs per Mode',
                default => 'QSOs per Hour',
            };

            $title = $baseTitle.' — '.self::TIME_RANGE_LABELS[$timeRange];

            return $this->formatChartData($rawData, $chartType, $title, $dataSource, $this->elapsedHoursInRange($timeRange));
        });
    }

    /**
     * Define Livewire event listeners for this widget.
     *
     * Refreshes immediately when a contact is logged, edited or deleted and
     * the viewer may subscribe to the event channel; the widget also polls,
     * which is all guests on the TV dashboard get.
     *
     * @return array<string, string>
     */
    public function getWidgetListeners(): array
    {
        return $this->contactChangeListeners();
    }

    /**
     * Render the chart widget view.
     */
    public function render(): View
    {
        $chartData = $this->getData();

        $this->chartPayload = collect($chartData)->except('last_updated_at')->all();

        return view('livewire.dashboard.widgets.chart', [
            'chartData' => $chartData,
        ]);
    }

    /**
     * Get QSO count grouped by hour for the active event.
     *
     * Returns hourly QSO counts over the event duration. Hours with
     * no contacts are included with a count of 0 for complete timelines.
     *
     * @return array<array{label: string, value: int}>
     */
    protected function getQsosPerHour(string $timeRange = 'event'): array
    {
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        if (! $event) {
            return [];
        }

        $eventConfig = $event->eventConfiguration;

        if (! $eventConfig) {
            return [];
        }

        $driver = DB::connection()->getDriverName();
        $hourExpression = match ($driver) {
            'sqlite' => DB::raw("strftime('%Y-%m-%d %H:00', qso_time) as hour_label"),
            default => DB::raw("DATE_FORMAT(qso_time, '%Y-%m-%d %H:00') as hour_label"),
        };

        $query = Contact::query()
            ->where('event_configuration_id', $eventConfig->id)
            ->where('is_duplicate', false)
            ->whereNotNull('qso_time');

        $since = $this->resolveSince($timeRange);

        if ($since !== null) {
            $query->where('qso_time', '>=', $since);
        }

        $contacts = $query
            ->select($hourExpression, DB::raw('COUNT(*) as count'))
            ->groupBy('hour_label')
            ->orderBy('hour_label')
            ->get();

        $contactsByHour = $contacts->pluck('count', 'hour_label')->toArray();

        $startHour = ($since ?? $event->start_time)->copy()->startOfHour();

        if ($startHour->lt($event->start_time)) {
            $startHour = $event->start_time->copy()->startOfHour();
        }

        $endHour = appNow()->copy()->startOfHour();

        if ($endHour->gt($event->end_time)) {
            $endHour = $event->end_time->copy()->startOfHour();
        }

        $results = [];
        $current = $startHour->copy();

        while ($current->lte($endHour)) {
            $key = $current->format('Y-m-d H:00');
            $label = $current->format('H:00');

            $results[] = [
                'label' => $label,
                'value' => $contactsByHour[$key] ?? 0,
            ];

            $current->addHour();
        }

        return $results;
    }

    /**
     * Get QSO count grouped by band for the active event.
     *
     * Joins with the bands table to get band names and respects
     * the bands sort_order for consistent ordering.
     *
     * @return array<array{label: string, value: int}>
     */
    protected function getQsosPerBand(string $timeRange = 'event'): array
    {
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        if (! $event) {
            return [];
        }

        $eventConfig = $event->eventConfiguration;

        if (! $eventConfig) {
            return [];
        }

        $query = Contact::query()
            ->where('event_configuration_id', $eventConfig->id)
            ->where('is_duplicate', false)
            ->join('bands', 'contacts.band_id', '=', 'bands.id');

        $since = $this->resolveSince($timeRange);

        if ($since !== null) {
            $query->where('qso_time', '>=', $since);
        }

        return $query
            ->select('bands.name as label', DB::raw('COUNT(*) as value'))
            ->groupBy('bands.name', 'bands.sort_order')
            ->orderBy('bands.sort_order')
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->value])
            ->toArray();
    }

    /**
     * Get QSO count grouped by mode for the active event.
     *
     * Joins with the modes table to get mode names.
     *
     * @return array<array{label: string, value: int}>
     */
    protected function getQsosPerMode(string $timeRange = 'event'): array
    {
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        if (! $event) {
            return [];
        }

        $eventConfig = $event->eventConfiguration;

        if (! $eventConfig) {
            return [];
        }

        $query = Contact::query()
            ->where('event_configuration_id', $eventConfig->id)
            ->where('is_duplicate', false)
            ->join('modes', 'contacts.mode_id', '=', 'modes.id');

        $since = $this->resolveSince($timeRange);

        if ($since !== null) {
            $query->where('qso_time', '>=', $since);
        }

        return $query
            ->select('modes.name as label', DB::raw('COUNT(*) as value'))
            ->groupBy('modes.name')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->value])
            ->toArray();
    }

    /**
     * Hours of operating time covered by the time range, clamped to the event window.
     *
     * Returns 0 when there is no context event or the event has not started.
     */
    protected function elapsedHoursInRange(string $timeRange): float
    {
        $event = app(EventContextService::class)->getContextEvent();

        if (! $event?->start_time || ! $event->end_time) {
            return 0.0;
        }

        $windowEnd = appNow()->min($event->end_time);
        $windowStart = $event->start_time->copy();
        $since = $this->resolveSince($timeRange);

        if ($since !== null && $windowStart->lt($since)) {
            $windowStart = Carbon::instance($since);
        }

        return max(0.0, $windowStart->diffInMinutes($windowEnd) / 60);
    }

    /**
     * Resolve the lower-bound timestamp for the configured time range.
     *
     * Returns null for the full-event view, in which case no qso_time filter is applied.
     */
    protected function resolveSince(string $timeRange): ?\DateTimeInterface
    {
        $hours = self::TIME_RANGE_HOURS[$timeRange] ?? null;

        if ($hours === null) {
            return null;
        }

        return appNow()->copy()->subHours($hours);
    }

    /**
     * Format raw data into a Chart.js-ready structure.
     *
     * Generates labels, datasets with appropriate colors, and metadata
     * for the Alpine.js chart component to consume.
     *
     * @param  array<array{label: string, value: int}>  $rawData
     * @return array{labels: array<string>, datasets: array<array<string, mixed>>, chart_type: string, title: string, description: string}
     */
    protected function formatChartData(array $rawData, string $chartType, string $title, string $dataSource, float $elapsedHours = 0.0): array
    {
        $labels = array_column($rawData, 'label');
        $values = array_column($rawData, 'value');

        $isPieType = in_array($chartType, ['pie', 'doughnut']);
        $totalQsos = array_sum($values);

        $description = $this->generateDescription($rawData, $title, $totalQsos);

        if ($isPieType) {
            $colors = array_slice(self::CHART_COLORS, 0, count($values));
            $borderColors = array_slice(self::CHART_BORDER_COLORS, 0, count($values));

            $datasets = [[
                'data' => $values,
                'backgroundColor' => $colors,
                'borderColor' => $borderColors,
                'borderWidth' => 2,
            ]];
        } else {
            $datasets = [[
                'label' => $title,
                'data' => $values,
                'backgroundColor' => self::CHART_COLORS[0],
                'borderColor' => self::CHART_BORDER_COLORS[0],
                'borderWidth' => 2,
                'tension' => $chartType === 'line' ? 0.3 : 0,
                'fill' => $chartType === 'line',
            ]];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
            'chart_type' => $chartType,
            'title' => $title,
            'description' => $description,
            'data_source' => $dataSource,
            'summary_value' => $this->formatSummaryValue($rawData, $dataSource, $elapsedHours),
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Generate a human-readable description of the chart data for screen readers.
     *
     * @param  array<array{label: string, value: int}>  $rawData
     */
    protected function generateDescription(array $rawData, string $title, int $totalQsos): string
    {
        if (empty($rawData)) {
            return "No data available for {$title}.";
        }

        $topItems = array_slice($rawData, 0, 3);
        $topDescriptions = array_map(
            fn ($item) => "{$item['label']}: {$item['value']}",
            $topItems
        );

        return "{$title} chart showing {$totalQsos} total QSOs. Top entries: "
            .implode(', ', $topDescriptions).'.';
    }

    /**
     * Generate a concise summary value for at-a-glance display.
     *
     * The hourly rate divides by real elapsed operating time, not by the
     * number of hour buckets: buckets include the partial first and current
     * hours, which would understate the rate.
     *
     * @param  array<array{label: string, value: int}>  $rawData
     */
    protected function formatSummaryValue(array $rawData, string $dataSource, float $elapsedHours = 0.0): string
    {
        if (empty($rawData)) {
            return '0';
        }

        $total = array_sum(array_column($rawData, 'value'));

        return match ($dataSource) {
            'qsos_per_hour' => number_format($total / max($elapsedHours, 1.0), 1).'/hr',
            'qsos_per_band' => number_format($total).' Qs',
            'qsos_per_mode' => number_format($total).' Qs',
            default => number_format($total),
        };
    }
}
