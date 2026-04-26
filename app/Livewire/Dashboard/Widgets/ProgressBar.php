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

    public function getWidgetListeners(): array
    {
        return [];
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
            'percentage' => $this->calculatePercentage($current, $target),
            'label' => "{$current}/{$target} QSOs to next milestone",
            'unit_label' => 'QSOs',
            'footer_label' => 'To next milestone',
            'is_milestone' => $current > 0 && $current % 50 === 0,
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
     * QSO progress toward a target derived from operating class transmitter count.
     *
     * Class codes like "3A" → 3 transmitters → 600 QSO target (200 per tx).
     * Falls back to 200 when no class is configured.
     *
     * @return array{current: int, target: int, percentage: float, label: string, unit_label: string, footer_label: string, is_milestone: bool}
     */
    protected function buildClassTarget(?Event $event): array
    {
        $config = $event?->eventConfiguration;
        $current = $config?->contacts()->notDuplicate()->count() ?? 0;

        $classCode = $config?->operatingClass?->code;
        $transmitters = $this->parseTransmitterCount($classCode);
        $target = max(self::QSOS_PER_TRANSMITTER_TARGET, $transmitters * self::QSOS_PER_TRANSMITTER_TARGET);

        $classLabel = $classCode ? "Class {$classCode}" : 'class target';

        return [
            'current' => $current,
            'target' => $target,
            'percentage' => $this->calculatePercentage($current, $target),
            'label' => "{$current}/{$target} QSOs toward {$classLabel}",
            'unit_label' => 'QSOs',
            'footer_label' => $classCode ? "Toward Class {$classCode} target" : 'Toward class target',
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
     * Extract the leading transmitter count from an operating class code.
     *
     * Examples: "1A" → 1, "20A" → 20, "B" → 0, null → 0.
     */
    protected function parseTransmitterCount(?string $classCode): int
    {
        if ($classCode === null || $classCode === '') {
            return 0;
        }

        if (preg_match('/^(\d+)/', $classCode, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
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
        $milestoneInterval = 50;

        // If exactly on a milestone, next one is current + interval
        if ($current % $milestoneInterval === 0) {
            return $current + $milestoneInterval;
        }

        // Otherwise, round up to next milestone
        return (int) (ceil($current / $milestoneInterval) * $milestoneInterval);
    }

    /**
     * Calculate percentage progress from current to next milestone.
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
