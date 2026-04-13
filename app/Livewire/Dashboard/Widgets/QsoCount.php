<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Concerns\HasErrorBoundary;
use App\Models\Contact;
use App\Models\Event;
use Livewire\Attributes\Computed;
use Livewire\Component;

class QsoCount extends Component
{
    use HasErrorBoundary;

    public bool $tvMode = false;

    public ?Event $event = null;

    /** @var int Cached count updated via broadcast */
    public int $cachedCount = 0;

    public function mount(bool $tvMode = false): void
    {
        $this->tvMode = $tvMode;
        $this->event = Event::active()->with('eventConfiguration')->first();
        $this->cachedCount = $this->fetchQsoCount();
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (! $this->event) {
            return [];
        }

        return [
            "echo-private:event.{$this->event->id},.ContactLogged" => 'handleContactLogged',
        ];
    }

    /**
     * Handle real-time ContactLogged broadcast.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleContactLogged(array $payload): void
    {
        try {
            $this->cachedCount = $payload['qso_count'] ?? $this->fetchQsoCount();
            unset($this->qsoRate);

            $this->dispatch('qso-logged', [
                'callsign' => $payload['callsign'] ?? '',
                'count' => $this->cachedCount,
            ]);
        } catch (\Throwable $e) {
            $this->handleWidgetError($e);
        }
    }

    #[Computed]
    public function qsoCount(): int
    {
        return $this->cachedCount;
    }

    #[Computed]
    public function qsoRate(): float
    {
        if (! $this->event) {
            return 0.0;
        }

        $elapsedMinutes = $this->event->start_time->diffInMinutes(appNow());

        if ($elapsedMinutes < 1) {
            return 0.0;
        }

        return round($this->cachedCount / $elapsedMinutes * 60, 1);
    }

    private function fetchQsoCount(): int
    {
        if (! $this->event?->eventConfiguration) {
            return 0;
        }

        return Contact::where('event_configuration_id', $this->event->eventConfiguration->id)
            ->notDuplicate()
            ->count();
    }

    protected function getWidgetName(): string
    {
        return 'QSO Count & Rate';
    }

    public function render()
    {
        if ($this->hasError) {
            return view('livewire.dashboard.widgets.error-fallback', [
                'widgetName' => $this->getWidgetName(),
                'errorMessage' => $this->errorMessage,
            ]);
        }

        return view('livewire.dashboard.widgets.qso-count');
    }
}
