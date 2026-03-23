<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\GuestbookEntry;
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

    #[Computed]
    public function guestbookStats(): array
    {
        if (! $this->event->eventConfiguration?->guestbook_enabled) {
            return [
                'total' => 0,
                'verified_bonus_eligible' => 0,
                'bonus_points' => 0,
            ];
        }

        $configId = $this->event->eventConfiguration->id;

        $total = GuestbookEntry::where('event_configuration_id', $configId)->count();

        $verifiedBonusEligible = GuestbookEntry::where('event_configuration_id', $configId)
            ->where('is_verified', true)
            ->bonusEligible()
            ->count();

        $bonusPoints = min($verifiedBonusEligible, 10) * 100;

        return [
            'total' => $total,
            'verified_bonus_eligible' => $verifiedBonusEligible,
            'bonus_points' => $bonusPoints,
        ];
    }

    public function render(): View
    {
        return view('livewire.events.event-dashboard')->layout('layouts.app');
    }
}
