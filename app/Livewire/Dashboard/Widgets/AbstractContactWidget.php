<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Livewire\Dashboard\Concerns\HasErrorBoundary;
use App\Models\Event;
use Livewire\Component;

/**
 * Base class for dashboard widgets that listen to real-time ContactLogged broadcasts.
 *
 * Subclasses must implement:
 * - computedPropertiesToClear(): array of computed property names to unset on new contacts
 * - getWidgetName(): human-readable name for error fallback
 * - getViewName(): Blade view path for the widget
 */
abstract class AbstractContactWidget extends Component
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
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (! $this->event) {
            return [];
        }

        return [
            "echo-private:event.{$this->event->id},ContactLogged" => 'handleContactLogged',
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
            foreach ($this->computedPropertiesToClear() as $property) {
                unset($this->{$property});
            }
        } catch (\Throwable $e) {
            $this->handleWidgetError($e);
        }
    }

    /**
     * Get the computed property names to clear when a new contact is logged.
     *
     * @return array<int, string>
     */
    abstract protected function computedPropertiesToClear(): array;

    /**
     * Get the Blade view name for this widget.
     */
    abstract protected function getViewName(): string;

    public function render()
    {
        if ($this->hasError) {
            return view('livewire.dashboard.widgets.error-fallback', [
                'widgetName' => $this->getWidgetName(),
                'errorMessage' => $this->errorMessage,
            ]);
        }

        return view($this->getViewName());
    }
}
