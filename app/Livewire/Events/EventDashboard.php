<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EventDashboard extends Component
{
    use AuthorizesRequests;

    public Event $event;

    public string $activeTab = 'overview';

    public function mount(Event $event): void
    {
        $this->authorize('view-events');

        // Load the event with all necessary relationships
        $this->event = $event->load([
            'eventType',
            'eventConfiguration.section',
            'eventConfiguration.operatingClass',
        ]);
    }

    #[Computed]
    public function isActive(): bool
    {
        return $this->event->id == Setting::get('active_event_id');
    }

    #[Computed]
    public function qsoBreakdown(): array
    {
        // Placeholder: Will be populated when Contact model is fully implemented
        return [
            'total_contacts' => 0,
            'phone_contacts' => 0,
            'cw_contacts' => 0,
            'digital_contacts' => 0,
        ];
    }

    #[Computed]
    public function participants(): array
    {
        // Placeholder: Will be populated when Contact model is fully implemented
        return [];
    }

    public function activate(): void
    {
        $this->authorize('activate-events');

        Setting::set('active_event_id', $this->event->id);

        $this->dispatch('notify', title: 'Success', description: "Event '{$this->event->name}' is now active.");
    }

    public function render(): View
    {
        return view('livewire.events.event-dashboard');
    }
}
