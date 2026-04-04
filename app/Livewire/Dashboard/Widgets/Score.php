<?php

namespace App\Livewire\Dashboard\Widgets;

use Livewire\Attributes\Computed;

class Score extends AbstractContactWidget
{
    #[Computed]
    public function qsoScore(): int
    {
        return $this->event?->eventConfiguration?->calculateQsoScore() ?? 0;
    }

    #[Computed]
    public function bonusScore(): int
    {
        return $this->event?->eventConfiguration?->calculateBonusScore() ?? 0;
    }

    #[Computed]
    public function finalScore(): int
    {
        return $this->event?->eventConfiguration?->calculateFinalScore() ?? 0;
    }

    #[Computed]
    public function powerMultiplier(): int
    {
        return $this->event?->eventConfiguration?->calculatePowerMultiplier() ?? 1;
    }

    protected function computedPropertiesToClear(): array
    {
        return ['qsoScore', 'bonusScore', 'finalScore'];
    }

    protected function getWidgetName(): string
    {
        return 'Current Score';
    }

    protected function getViewName(): string
    {
        return 'livewire.dashboard.widgets.score';
    }
}
