<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Models\BonusType;
use App\Models\Event;
use App\Services\EventContextService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * ProgressBar Widget - Shows progress toward a chosen target.
 *
 * Supported metrics:
 * - next_milestone: QSOs progress toward next 50-QSO milestone
 * - event_goal: Score progress toward a fixed event score goal (default 5000)
 * - class_target: QSO progress toward a target derived from operating class
 * - bonus_progress: Bonus points earned vs. max possible for the rule set
 */
class ProgressBar extends Component
{
    use IsWidget;

    /**
     * Default score goal used by the event_goal metric when no event-level
     * goal has been recorded.
     */
    protected const DEFAULT_SCORE_GOAL = 5000;

    /**
     * Default per-transmitter QSO target used by class_target.
     */
    protected const QSOS_PER_TRANSMITTER_TARGET = 200;

    /**
     * QSO spacing between next_milestone targets.
     */
    public const MILESTONE_INTERVAL = 50;

    /**
     * Current value exposed as a reactive Livewire property.
     * Alpine watches this via $wire.$watch to trigger animations.
     */
    public int $current = 0;

    /**
     * Progress percentage toward target, exposed as a reactive property.
     */
    public float $percentage = 0;

    public function getData(): array
    {
        return Cache::remember(
            $this->cacheKey(),
            3,
            function () {
                $metric = $this->config['metric'] ?? 'next_milestone';
                $service = app(EventContextService::class);
                $event = $service->getContextEvent();

                $payload = match ($metric) {
                    'event_goal' => $this->buildEventGoal($event),
                    'class_target' => $this->buildClassTarget($event),
                    'bonus_progress' => $this->buildBonusProgress($event),
                    default => $this->buildNextMilestone($event),
                };

                return $payload + ['last_updated_at' => appNow()];
            }
        );
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
     * Next 50-QSO milestone metric.
     *
     * @return array{current: int, target: int, percentage: float, label: string, unit_label: string, footer_label: string, is_milestone: bool}
     */
    protected function buildNextMilestone(?Event $event): array
    {
        $current = $event?->eventConfiguration?->contacts()->notDuplicate()->count() ?? 0;
        $target = $this->calculateNextMilestone($current);

        return [
            'current' => $current,
            'target' => $target,
            'percentage' => $this->calculateMilestonePercentage($current, $target),
            'label' => "{$current}/{$target} QSOs to next milestone",
            'unit_label' => 'QSOs',
            'footer_label' => 'To next milestone',
            'is_milestone' => $current > 0 && $current % self::MILESTONE_INTERVAL === 0,
        ];
    }

    /**
     * Total-score progress toward a fixed event score goal.
     *
     * @return array{current: int, target: int, percentage: float, label: string, unit_label: string, footer_label: string, is_milestone: bool}
     */
    protected function buildEventGoal(?Event $event): array
    {
        $current = (int) ($event?->eventConfiguration?->calculateFinalScore() ?? 0);
        $target = self::DEFAULT_SCORE_GOAL;

        return [
            'current' => $current,
            'target' => $target,
            'percentage' => $this->calculatePercentage($current, $target),
            'label' => "{$current}/{$target} points to event goal",
            'unit_label' => 'points',
            'footer_label' => 'To event score goal',
            'is_milestone' => false,
        ];
    }

    /**
     * QSO progress toward a target derived from the configured transmitter count.
     *
     * A "3A" entry → 3 transmitters → 600 QSO target (200 per transmitter).
     * Falls back to a single transmitter when nothing is configured.
     *
     * @return array{current: int, target: int, percentage: float, label: string, unit_label: string, footer_label: string, is_milestone: bool}
     */
    protected function buildClassTarget(?Event $event): array
    {
        $config = $event?->eventConfiguration;
        $current = $config?->contacts()->notDuplicate()->count() ?? 0;

        $transmitters = max(1, (int) ($config?->transmitter_count ?? 1));
        $target = $transmitters * self::QSOS_PER_TRANSMITTER_TARGET;

        $classCode = $config?->operatingClass?->code;
        $entryClass = $classCode ? "{$transmitters}{$classCode}" : null;
        $classLabel = $entryClass ? "Class {$entryClass}" : 'class target';

        return [
            'current' => $current,
            'target' => $target,
            'percentage' => $this->calculatePercentage($current, $target),
            'label' => "{$current}/{$target} QSOs toward {$classLabel}",
            'unit_label' => 'QSOs',
            'footer_label' => $entryClass ? "Toward Class {$entryClass} target" : 'Toward class target',
            'is_milestone' => false,
        ];
    }

    /**
     * Bonus-point progress vs. maximum bonus points for the event's rule set.
     *
     * Target sums BonusType.max_points for the event's resolved rules version,
     * which gives a conservative ceiling (per-transmitter bonuses still cap at
     * their max_points value, matching how operators read the rules).
     *
     * @return array{current: int, target: int, percentage: float, label: string, unit_label: string, footer_label: string, is_milestone: bool}
     */
    protected function buildBonusProgress(?Event $event): array
    {
        $config = $event?->eventConfiguration;
        $current = (int) ($config?->bonuses()->sum('calculated_points') ?? 0);

        $target = 0;

        if ($event && $event->resolved_rules_version) {
            $target = (int) BonusType::query()
                ->where('event_type_id', $event->event_type_id)
                ->where('rules_version', $event->resolved_rules_version)
                ->sum('max_points');
        }

        if ($target <= 0) {
            $target = max($current, 1);
        }

        return [
            'current' => $current,
            'target' => $target,
            'percentage' => $this->calculatePercentage($current, $target),
            'label' => "{$current}/{$target} bonus points",
            'unit_label' => 'bonus pts',
            'footer_label' => 'Toward maximum bonus points',
            'is_milestone' => false,
        ];
    }

    /**
     * Calculate the next milestone (next multiple of 50).
     *
     * Examples:
     * - 37 QSOs → 50
     * - 50 QSOs → 100
     * - 187 QSOs → 200
     */
    public function calculateNextMilestone(int $current): int
    {
        return (intdiv($current, self::MILESTONE_INTERVAL) + 1) * self::MILESTONE_INTERVAL;
    }

    /**
     * Calculate progress made since the previous milestone toward the next one.
     *
     * Measuring from zero would pin the bar near 100% once the count grows
     * (1,001 of 1,050 is already 95%), so only the current 50-QSO stretch counts.
     *
     * Examples:
     * - 37 toward 50 → 74%
     * - 132 toward 150 → 64%
     * - 150 toward 200 → 0%
     */
    public function calculateMilestonePercentage(int $current, int $target): float
    {
        $previousMilestone = $target - self::MILESTONE_INTERVAL;

        return $this->calculatePercentage($current - $previousMilestone, self::MILESTONE_INTERVAL);
    }

    /**
     * Calculate percentage progress from current toward a target.
     *
     * Examples:
     * - 37/50 → 74%
     * - 53/100 → 53%
     * - 187/200 → 93.5%
     */
    public function calculatePercentage(int $current, int $target): float
    {
        if ($target === 0) {
            return 0;
        }

        return round(($current / $target) * 100, 1);
    }

    public function render()
    {
        $data = $this->getData();

        $this->current = $data['current'];
        $this->percentage = (float) $data['percentage'];

        return view('livewire.dashboard.widgets.progress-bar', [
            'data' => $data,
            'showPercentage' => (bool) ($this->config['show_percentage'] ?? true),
            'celebratesMilestones' => ($this->config['metric'] ?? 'next_milestone') === 'next_milestone',
        ]);
    }
}
