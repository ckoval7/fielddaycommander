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
        $config = $this->event->eventConfiguration;

        if (! $config) {
            return [
                'total_contacts' => 0,
                'phone_contacts' => 0,
                'cw_contacts' => 0,
                'digital_contacts' => 0,
            ];
        }

        $totalContacts = $config->contacts()->count();

        $categoryCounts = $config->contacts()
            ->notDuplicate()
            ->join('modes', 'contacts.mode_id', '=', 'modes.id')
            ->selectRaw('modes.category, count(*) as count')
            ->groupBy('modes.category')
            ->pluck('count', 'category');

        return [
            'total_contacts' => $totalContacts,
            'phone_contacts' => (int) ($categoryCounts['Phone'] ?? 0),
            'cw_contacts' => (int) ($categoryCounts['CW'] ?? 0),
            'digital_contacts' => (int) ($categoryCounts['Digital'] ?? 0),
        ];
    }

    #[Computed]
    public function participants(): array
    {
        $config = $this->event->eventConfiguration;

        if (! $config) {
            return [];
        }

        return $config->contacts()
            ->join('users', 'contacts.logger_user_id', '=', 'users.id')
            ->selectRaw('users.id, users.call_sign, count(*) as contact_count')
            ->groupBy('users.id', 'users.call_sign')
            ->orderByDesc('contact_count')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->call_sign,
                'contact_count' => (int) $row->contact_count,
            ])
            ->toArray();
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
