<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\GuestbookEntry;
use App\Models\Station;
use App\Services\EventContextService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * StatCard Widget
 *
 * Displays a single metric from the active event in a card format.
 * Supports multiple metric types and size variants (normal/tv).
 *
 * Metric Types:
 * - total_score: Sum of all QSO points for active event
 * - qso_count: Count of contacts for active event
 * - sections_worked: Count of unique sections worked
 * - operators_count: Count of unique operators
 *
 * Config structure:
 * [
 *   'metric' => 'total_score|qso_count|sections_worked|operators_count|guestbook_count'
 * ]
 */
class StatCard extends Component
{
    use IsWidget;

    /**
     * Current metric value exposed as a reactive Livewire property.
     * Alpine watches this via $wire.$watch to trigger count-up animation.
     */
    public string $statValue = '0';

    /**
     * Fetch the metric data for this widget.
     *
     * Returns an array with the metric value, label, and icon.
     */
    public function getData(): array
    {
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        if ($this->shouldCache()) {
            $data = Cache::remember(
                $this->cacheKey(),
                now()->addSeconds(3),
                fn () => $this->calculateMetric()
            );
        } else {
            $data = $this->calculateMetric();
        }

        // Add comparison data if enabled
        if ($event) {
            $data = $this->addComparisonData($data, $event);
        }

        return $data;
    }

    /**
     * Define Livewire event listeners for this widget.
     *
     * Returns empty array for now - batched updates handled via polling.
     */
    public function getWidgetListeners(): array
    {
        return [];
    }

    /**
     * Calculate the metric value based on widget config.
     */
    protected function calculateMetric(): array
    {
        $metric = $this->config['metric'] ?? 'qso_count';
        $service = app(EventContextService::class);
        $event = $service->getContextEvent();

        if (! $event || ! $event->eventConfiguration) {
            return $this->emptyMetric($metric);
        }

        return match ($metric) {
            'total_score' => $this->getTotalScore($event),
            'qso_count' => $this->getQsoCount($event),
            'sections_worked' => $this->getSectionsWorked($event),
            'operators_count' => $this->getOperatorsCount($event),
            'stations_count' => $this->getStationsCount($event),
            'qso_per_hour' => $this->getQsoPerHour($event),
            'points_per_hour' => $this->getPointsPerHour($event),
            'avg_qso_rate_4h' => $this->getAvgQsoRate4h($event),
            'contacts_last_hour' => $this->getContactsLastHour($event),
            'hours_remaining' => $this->getHoursRemaining($event),
            'bonus_points_earned' => $this->getBonusPointsEarned($event),
            'guestbook_count' => $this->getGuestbookCount($event),
            default => $this->emptyMetric($metric),
        };
    }

    /**
     * Get total score metric.
     */
    protected function getTotalScore(Event $event): array
    {
        $score = $event->eventConfiguration->calculateFinalScore();

        return [
            'value' => number_format($score),
            'label' => 'Total Score',
            'icon' => 'phosphor-trophy',
            'color' => 'text-success',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get QSO count metric.
     */
    protected function getQsoCount(Event $event): array
    {
        $count = Contact::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->notDuplicate()
            ->count();

        return [
            'value' => number_format($count),
            'label' => 'QSOs',
            'icon' => 'phosphor-chat-centered-dots',
            'color' => 'text-primary',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get sections worked metric.
     */
    protected function getSectionsWorked(Event $event): array
    {
        $count = Contact::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->notDuplicate()
            ->whereNotNull('section_id')
            ->distinct('section_id')
            ->count('section_id');

        return [
            'value' => number_format($count),
            'label' => 'Sections',
            'icon' => 'phosphor-map-trifold',
            'color' => 'text-info',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get operators count metric.
     */
    protected function getOperatorsCount(Event $event): array
    {
        $count = Contact::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->join('operating_sessions', 'contacts.operating_session_id', '=', 'operating_sessions.id')
            ->distinct('operating_sessions.operator_user_id')
            ->count('operating_sessions.operator_user_id');

        return [
            'value' => number_format($count),
            'label' => 'Operators',
            'icon' => 'phosphor-users',
            'color' => 'text-warning',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get count of stations with at least one open operating session.
     */
    protected function getStationsCount(Event $event): array
    {
        $count = Station::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->whereHas('operatingSessions', fn ($query) => $query->active())
            ->count();

        return [
            'value' => number_format($count),
            'label' => 'Active Stations',
            'icon' => 'phosphor-broadcast',
            'color' => 'text-warning',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get QSOs-per-hour rate since event start.
     */
    protected function getQsoPerHour(Event $event): array
    {
        $elapsedHours = $event->start_time->diffInMinutes(appNow()) / 60;

        $count = Contact::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->notDuplicate()
            ->count();

        $rate = $elapsedHours > 0 ? $count / $elapsedHours : 0;

        return [
            'value' => number_format($rate, 1),
            'label' => 'QSOs / Hour',
            'icon' => 'phosphor-lightning',
            'color' => 'text-info',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get points-per-hour rate since event start.
     */
    protected function getPointsPerHour(Event $event): array
    {
        $elapsedHours = $event->start_time->diffInMinutes(appNow()) / 60;

        $score = $event->eventConfiguration->calculateFinalScore();

        $rate = $elapsedHours > 0 ? $score / $elapsedHours : 0;

        return [
            'value' => number_format($rate, 1),
            'label' => 'Points / Hour',
            'icon' => 'phosphor-lightning',
            'color' => 'text-success',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get 4-hour rolling average QSO rate metric.
     */
    protected function getAvgQsoRate4h(Event $event): array
    {
        $fourHoursAgo = appNow()->subHours(4);

        $count = Contact::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->notDuplicate()
            ->where('qso_time', '>=', $fourHoursAgo)
            ->count();

        // Calculate hourly average: (count / 4)
        $avgRate = $count / 4;

        return [
            'value' => number_format($avgRate, 1),
            'label' => 'Avg QSO Rate (4h)',
            'icon' => 'phosphor-chart-bar',
            'color' => 'text-info',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get contacts in last hour metric.
     */
    protected function getContactsLastHour(Event $event): array
    {
        $oneHourAgo = appNow()->subHour();

        $count = Contact::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->notDuplicate()
            ->where('qso_time', '>=', $oneHourAgo)
            ->count();

        return [
            'value' => number_format($count),
            'label' => 'Contacts Last Hour',
            'icon' => 'phosphor-clock',
            'color' => 'text-success',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get hours remaining in event metric.
     */
    protected function getHoursRemaining(Event $event): array
    {
        // Calculate hours remaining
        // If end_time is in the future, this returns positive; if past, returns negative
        $hoursRemaining = appNow()->diffInHours($event->end_time, false);

        // If negative (event ended), return 0
        if ($hoursRemaining < 0) {
            $hoursRemaining = 0;
        }

        return [
            'value' => number_format($hoursRemaining),
            'label' => 'Hours Remaining',
            'icon' => 'phosphor-clock',
            'color' => 'text-warning',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get accumulated bonus points metric.
     */
    protected function getBonusPointsEarned(Event $event): array
    {
        $bonusPoints = EventBonus::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->sum('calculated_points') ?? 0;

        return [
            'value' => number_format($bonusPoints),
            'label' => 'Bonus Points',
            'icon' => 'phosphor-star',
            'color' => 'text-accent',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Get guestbook entry count metric.
     */
    protected function getGuestbookCount(Event $event): array
    {
        $count = GuestbookEntry::query()
            ->where('event_configuration_id', $event->eventConfiguration->id)
            ->count();

        return [
            'value' => number_format($count),
            'label' => 'Guestbook Entries',
            'icon' => 'phosphor-book-open',
            'color' => 'text-info',
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Return empty metric data.
     */
    protected function emptyMetric(string $metric): array
    {
        $labels = [
            'total_score' => ['Total Score', 'phosphor-trophy', 'text-success'],
            'qso_count' => ['QSOs', 'phosphor-chat-centered-dots', 'text-primary'],
            'sections_worked' => ['Sections', 'phosphor-map-trifold', 'text-info'],
            'operators_count' => ['Operators', 'phosphor-users', 'text-warning'],
            'stations_count' => ['Active Stations', 'phosphor-broadcast', 'text-warning'],
            'qso_per_hour' => ['QSOs / Hour', 'phosphor-lightning', 'text-info'],
            'points_per_hour' => ['Points / Hour', 'phosphor-lightning', 'text-success'],
            'avg_qso_rate_4h' => ['Avg QSO Rate (4h)', 'phosphor-chart-bar', 'text-info'],
            'contacts_last_hour' => ['Contacts Last Hour', 'phosphor-clock', 'text-success'],
            'hours_remaining' => ['Hours Remaining', 'phosphor-clock', 'text-warning'],
            'bonus_points_earned' => ['Bonus Points', 'phosphor-star', 'text-accent'],
            'guestbook_count' => ['Guestbook Entries', 'phosphor-book-open', 'text-info'],
        ];

        [$label, $icon, $color] = $labels[$metric] ?? ['Unknown', 'phosphor-question', 'text-base-content'];

        return [
            'value' => '0',
            'label' => $label,
            'icon' => $icon,
            'color' => $color,
            'last_updated_at' => appNow(),
        ];
    }

    /**
     * Add comparison data to the metric if enabled.
     *
     * Compares current value with historical snapshot from cache.
     * Calculates change amount, percentage, and trend direction.
     */
    protected function addComparisonData(array $current, Event $event): array
    {
        $showComparison = $this->config['show_comparison'] ?? true;

        if (! $showComparison || ! $event->eventConfiguration) {
            return $current;
        }

        $interval = $this->config['comparison_interval'] ?? '1h';
        $configHash = md5(json_encode($this->config));
        $historicalKey = "dashboard:widget:StatCard:{$configHash}:{$event->eventConfiguration->id}:history:{$interval}";

        $previousValue = Cache::get($historicalKey);
        $currentNumeric = (float) str_replace(',', '', $current['value']);

        $ttl = $this->comparisonCacheTtl($interval);
        Cache::put($historicalKey, $currentNumeric, $ttl);

        if ($previousValue === null) {
            return $current;
        }

        return array_merge($current, $this->buildComparisonMetrics($currentNumeric, $previousValue, $interval));
    }

    /**
     * Get the cache TTL for comparison historical data.
     */
    protected function comparisonCacheTtl(string $interval): \DateTimeInterface
    {
        return match ($interval) {
            '1h' => now()->addHours(1)->addHour(),
            '4h' => now()->addHours(4)->addHour(),
            default => now()->addHours(2),
        };
    }

    /**
     * Build comparison metric fields from current and previous values.
     *
     * @return array{previous_value: float, change_amount: float, change_percentage: float, trend: string, comparison_label: string}
     */
    protected function buildComparisonMetrics(float $currentNumeric, float $previousValue, string $interval): array
    {
        $changeAmount = $currentNumeric - $previousValue;

        return [
            'previous_value' => $previousValue,
            'change_amount' => $changeAmount,
            'change_percentage' => round(
                $previousValue > 0 ? (($currentNumeric - $previousValue) / $previousValue) * 100 : 0,
                1
            ),
            'trend' => match (true) {
                $changeAmount > 0 => 'up',
                $changeAmount < 0 => 'down',
                default => 'stable',
            },
            'comparison_label' => match ($interval) {
                '1h' => 'vs 1h ago',
                '4h' => 'vs 4h ago',
                default => 'vs earlier',
            },
        ];
    }

    public function render()
    {
        $data = $this->getData();

        $this->statValue = (string) $data['value'];

        return view('livewire.dashboard.widgets.stat-card', [
            'data' => $data,
            'showTrend' => (bool) ($this->config['show_trend'] ?? true),
        ]);
    }
}
