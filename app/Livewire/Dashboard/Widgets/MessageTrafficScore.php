<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Concerns\HasErrorBoundary;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use Livewire\Attributes\Computed;
use Livewire\Component;

class MessageTrafficScore extends Component
{
    use HasErrorBoundary;

    public bool $tvMode = false;

    public ?Event $event = null;

    public function mount(bool $tvMode = false): void
    {
        $this->tvMode = $tvMode;
        $this->event = Event::active()->with('eventConfiguration')->first();
    }

    /**
     * @return array{sm_message: bool, sm_points: int, traffic_count: int, traffic_points: int, w1aw_bulletin: bool, w1aw_points: int, total: int}
     */
    #[Computed]
    public function bonusSummary(): array
    {
        if (! $this->event?->eventConfiguration) {
            return ['sm_message' => false, 'sm_points' => 0, 'traffic_count' => 0, 'traffic_points' => 0, 'w1aw_bulletin' => false, 'w1aw_points' => 0, 'total' => 0];
        }

        return $this->buildBonusSummary($this->event->eventConfiguration);
    }

    /**
     * @return array{sm_message: bool, sm_points: int, traffic_count: int, traffic_points: int, w1aw_bulletin: bool, w1aw_points: int, total: int}
     */
    private function buildBonusSummary(EventConfiguration $config): array
    {
        $rows = EventBonus::where('event_configuration_id', $config->id)
            ->with('bonusType')
            ->get();

        $smPoints = (int) ($rows->firstWhere('bonusType.code', 'sm_sec_message')?->calculated_points ?? 0);
        $trafficCount = (int) ($rows->firstWhere('bonusType.code', 'nts_message')?->quantity ?? 0);
        $trafficPoints = (int) ($rows->firstWhere('bonusType.code', 'nts_message')?->calculated_points ?? 0);
        $w1awPoints = (int) ($rows->firstWhere('bonusType.code', 'w1aw_bulletin')?->calculated_points ?? 0);

        return [
            'sm_message' => $smPoints > 0,
            'sm_points' => $smPoints,
            'traffic_count' => $trafficCount,
            'traffic_points' => $trafficPoints,
            'w1aw_bulletin' => $w1awPoints > 0,
            'w1aw_points' => $w1awPoints,
            'total' => $smPoints + $trafficPoints + $w1awPoints,
        ];
    }

    public function render()
    {
        if ($this->hasError) {
            return view('livewire.dashboard.widgets.error-fallback', [
                'widgetName' => 'Message Traffic Score',
                'errorMessage' => $this->errorMessage,
            ]);
        }

        return view('livewire.dashboard.widgets.message-traffic-score');
    }
}
