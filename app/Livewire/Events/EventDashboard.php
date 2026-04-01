<?php

namespace App\Livewire\Events;

use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\Mode;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EventDashboard extends Component
{
    use AuthorizesRequests;

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
            ->with(['band', 'mode', 'logger'])
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
                'verified_bonus_eligible' => 0,
                'bonus_points' => 0,
            ];
        }

        $configId = $this->config()->id;

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

    #[Computed]
    public function bandModeGrid(): array
    {
        $config = $this->config();

        if (! $config) {
            return [];
        }

        $counts = Contact::where('event_configuration_id', $config->id)
            ->notDuplicate()
            ->selectRaw('band_id, mode_id, count(*) as contact_count, sum(points) as total_points')
            ->groupBy('band_id', 'mode_id')
            ->get()
            ->groupBy('mode_id');

        $data = [];

        foreach ($this->modes as $mode) {
            $modeCounts = $counts->get($mode->id, collect());
            $cells = [];
            $totalCount = 0;
            $totalPoints = 0;

            foreach ($this->bands as $band) {
                $entry = $modeCounts->firstWhere('band_id', $band->id);
                $count = $entry ? (int) $entry->contact_count : 0;
                $cells[$band->id] = $count;
                $totalCount += $count;
                $totalPoints += $entry ? (int) $entry->total_points : 0;
            }

            $data[] = [
                'mode' => $mode,
                'cells' => $cells,
                'total_count' => $totalCount,
                'total_points' => $totalPoints,
            ];
        }

        return $data;
    }

    #[Computed]
    public function bandColumnTotals(): array
    {
        $totals = [];

        foreach ($this->bandModeGrid as $row) {
            foreach ($row['cells'] as $bandId => $count) {
                $totals[$bandId] = ($totals[$bandId] ?? 0) + $count;
            }
        }

        return $totals;
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

        $list = [];

        foreach ($bonusTypes as $bonusType) {
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

    public function render(): View
    {
        return view('livewire.events.event-dashboard')->layout('layouts.app');
    }
}
