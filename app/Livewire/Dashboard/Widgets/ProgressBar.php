<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use App\Services\ActiveEventService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * ProgressBar Widget - Shows progress toward next 50-QSO milestone.
 *
 * Displays a progress bar showing how close the active event is to the next
 * milestone (50, 100, 150, 200, etc. QSOs). Helps operators track goals.
 *
 * Metric: next_milestone
 * - current: Current QSO count
 * - target: Next milestone (next multiple of 50)
 * - percentage: Progress percentage (0-100)
 * - label: Display text like "37/50 QSOs to next milestone"
 */
class ProgressBar extends Component
{
    use IsWidget;

    public function getData(): array
    {
        return Cache::remember(
            $this->cacheKey(),
            3,
            function () {
                $activeEventService = app(ActiveEventService::class);
                $event = $activeEventService->getActiveEvent();

                if (! $event) {
                    return [
                        'current' => 0,
                        'target' => 50,
                        'percentage' => 0,
                        'label' => '0/50 QSOs to next milestone',
                    ];
                }

                $current = $event->eventConfiguration?->contacts()->notDuplicate()->count() ?? 0;
                $target = $this->calculateNextMilestone($current);
                $percentage = $this->calculatePercentage($current, $target);

                return [
                    'current' => $current,
                    'target' => $target,
                    'percentage' => $percentage,
                    'label' => "{$current}/{$target} QSOs to next milestone",
                ];
            }
        );
    }

    public function getWidgetListeners(): array
    {
        return [];
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
        return view('livewire.dashboard.widgets.progress-bar', [
            'data' => $this->getData(),
        ]);
    }
}
