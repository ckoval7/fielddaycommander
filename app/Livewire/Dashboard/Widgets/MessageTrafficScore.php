<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Concerns\HasErrorBoundary;
use App\Models\Event;
use App\Services\MessageBonusSyncService;
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

        return app(MessageBonusSyncService::class)->bonusSummary($this->event->eventConfiguration);
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
