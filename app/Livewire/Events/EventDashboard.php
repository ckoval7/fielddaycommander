<?php

namespace App\Livewire\Events;

use App\Livewire\Concerns\HasBandModeGrid;
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\Mode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EventDashboard extends Component
{
    use AuthorizesRequests;
    use HasBandModeGrid;

    public Event $event;

    public string $activeTab = 'overview';

    protected function config(): ?EventConfiguration
    {
        return $this->event->eventConfiguration;
    }

    public function mount(Event $event): void
    {
        $this->authorize('view-events');

        // Load the event with all necessary relationships
        $this->event = $event->load([
            'eventType',
            'eventConfiguration.section',
            'eventConfiguration.operatingClass',
            'eventConfiguration.bonuses',
        ]);
    }

    #[Computed]
    public function qsoBreakdown(): array
    {
        $config = $this->config();

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
        $config = $this->config();

        if (! $config) {
            return [];
        }

        return $config->contacts()
            ->join('users', 'contacts.logger_user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
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
    public function recentContacts(): Collection
    {
        $config = $this->config();

        if (! $config) {
            return collect();
        }

        return $config->contacts()
            ->with(['band', 'mode', 'logger', 'gotaOperator'])
            ->latest('qso_time')
            ->limit(25)
            ->get();
    }

    #[Computed]
    public function guestbookStats(): array
    {
        if (! $this->config()?->guestbook_enabled) {
            return [
                'total' => 0,
                'elected_official' => false,
                'agency' => false,
                'media' => false,
                'bonus_points' => 0,
            ];
        }

        $configId = $this->config()->id;

        $total = GuestbookEntry::where('event_configuration_id', $configId)->count();

        $hasElected = GuestbookEntry::where('event_configuration_id', $configId)
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL)
            ->where('is_verified', true)
            ->exists();

        $hasAgency = GuestbookEntry::where('event_configuration_id', $configId)
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_AGENCY)
            ->where('is_verified', true)
            ->exists();

        $hasMedia = GuestbookEntry::where('event_configuration_id', $configId)
            ->where('visitor_category', GuestbookEntry::VISITOR_CATEGORY_MEDIA)
            ->where('is_verified', true)
            ->exists();

        $bonusPoints = ($hasElected ? 100 : 0) + ($hasAgency ? 100 : 0) + ($hasMedia ? 100 : 0);

        return [
            'total' => $total,
            'elected_official' => $hasElected,
            'agency' => $hasAgency,
            'media' => $hasMedia,
            'bonus_points' => $bonusPoints,
        ];
    }

    #[Computed]
    public function scoringTotals(): array
    {
        $config = $this->config();

        if (! $config) {
            return [
                'qso_base_points' => 0,
                'power_multiplier' => 1,
                'qso_score' => 0,
                'bonus_score' => 0,
                'gota_bonus' => 0,
                'has_gota' => false,
                'final_score' => 0,
            ];
        }

        return [
            'qso_base_points' => (int) $config->contacts()->notDuplicate()->where('is_gota_contact', false)->sum('points'),
            'power_multiplier' => (int) $config->calculatePowerMultiplier(),
            'qso_score' => $config->calculateQsoScore(),
            'bonus_score' => $config->calculateBonusScore(),
            'gota_bonus' => $config->calculateGotaBonus() + $config->calculateGotaCoachBonus(),
            'has_gota' => $config->has_gota_station,
            'final_score' => $config->calculateFinalScore(),
        ];
    }

    #[Computed]
    public function bands(): Collection
    {
        return Band::allowedForFieldDay()->ordered()->get();
    }

    #[Computed]
    public function modes(): Collection
    {
        return Mode::orderBy('name')->get();
    }

    protected function bandModeGridQuery(): Builder
    {
        return Contact::where('event_configuration_id', $this->config()->id)
            ->notDuplicate();
    }

    #[Computed]
    public function bonusList(): array
    {
        $eventTypeId = $this->event->event_type_id;

        $query = BonusType::where('is_active', true);

        if ($eventTypeId) {
            $query->where('event_type_id', $eventTypeId);
        }

        $bonusTypes = $query->orderByDesc('base_points')->get();

        $config = $this->config();
        $claimedBonuses = $config
            ? $config->bonuses->keyBy('bonus_type_id')
            : collect();

        $emergencyPowerPoints = $config?->calculateEmergencyPowerBonus() ?? 0;

        $list = [];

        foreach ($bonusTypes as $bonusType) {
            // Emergency power is computed on-demand from config (per-transmitter)
            if ($bonusType->code === 'emergency_power') {
                $list[] = [
                    'type' => $bonusType,
                    'bonus' => null,
                    'status' => $emergencyPowerPoints > 0 ? 'verified' : 'unclaimed',
                    'points' => $emergencyPowerPoints,
                ];

                continue;
            }

            $eventBonus = $claimedBonuses->get($bonusType->id);

            if ($eventBonus && $eventBonus->is_verified) {
                $status = 'verified';
                $points = (int) $eventBonus->calculated_points;
            } elseif ($eventBonus) {
                $status = 'claimed';
                $points = (int) $eventBonus->calculated_points;
            } else {
                $status = 'unclaimed';
                $points = 0;
            }

            $list[] = [
                'type' => $bonusType,
                'bonus' => $eventBonus,
                'status' => $status,
                'points' => $points,
            ];
        }

        return $list;
    }

    #[Computed]
    public function equipmentCommitments(): Collection
    {
        return EquipmentEvent::where('event_id', $this->event->id)
            ->with(['equipment.owner', 'equipment.owningOrganization', 'station'])
            ->orderBy('status')
            ->get();
    }

    #[Computed]
    public function bonusSummary(): array
    {
        $list = collect($this->bonusList);

        return [
            'verified_pts' => (int) $list->where('status', 'verified')->sum('points'),
            'claimed_pts' => (int) $list->where('status', 'claimed')->sum('points'),
            'unclaimed_count' => $list->where('status', 'unclaimed')->count(),
        ];
    }

    public function delete(): void
    {
        $this->authorize('delete-events');

        $event = $this->event;
        $hasContacts = $event->eventConfiguration?->hasContacts() ?? false;

        AuditLog::log('event.deleted', auditable: $event, oldValues: [
            'name' => $event->name,
            'year' => $event->year,
            'has_contacts' => $hasContacts,
        ]);

        if ($hasContacts) {
            $event->delete();
            $this->dispatch('notify', title: 'Event Archived', description: "Event '{$event->name}' has been archived (soft deleted) because it has contacts.");
        } else {
            $event->forceDelete();
            $this->dispatch('notify', title: 'Event Deleted', description: "Event '{$event->name}' has been permanently deleted.");
        }

        $this->redirect(route('events.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.events.event-dashboard')->layout('layouts.app');
    }
}
