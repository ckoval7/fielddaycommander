<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Events;

use App\Enums\VisitorCategory;
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
use App\Models\Station;
use App\Scoring\Dto\Nomenclature;
use App\Scoring\Dto\ScoreBreakdown;
use App\Scoring\Dto\ScoreTerm;
use App\Scoring\RuleSetFactory;
use App\Services\RescoreService;
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

    public bool $showRescoreModal = false;

    public string $rescoreTargetVersion = '';

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

    /**
     * Guestbook visitor total and the guestbook bonuses this event's rulebook awards.
     *
     * Elected official and agency are null when the rulebook has no such bonus.
     *
     * @return array{total: int, elected_official: ?bool, agency: ?bool, bonus_points: int}
     */
    #[Computed]
    public function guestbookStats(): array
    {
        if (! $this->config()?->guestbook_enabled) {
            return [
                'total' => 0,
                'elected_official' => null,
                'agency' => null,
                'bonus_points' => 0,
            ];
        }

        $configId = $this->config()->id;
        $bonusCategories = $this->event->guestbookBonusCategories();

        $total = GuestbookEntry::where('event_configuration_id', $configId)->count();

        $visited = fn (VisitorCategory $category): ?bool => in_array($category, $bonusCategories, true)
            ? GuestbookEntry::where('event_configuration_id', $configId)
                ->where('visitor_category', $category)
                ->where('is_verified', true)
                ->exists()
            : null;

        $hasElected = $visited(VisitorCategory::ElectedOfficial);
        $hasAgency = $visited(VisitorCategory::Agency);

        $bonusPoints = ($hasElected ? 100 : 0) + ($hasAgency ? 100 : 0);

        return [
            'total' => $total,
            'elected_official' => $hasElected,
            'agency' => $hasAgency,
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
            'qso_base_points' => $this->scoreBreakdown?->value('qso_points') ?? 0,
            'power_multiplier' => (int) $config->calculatePowerMultiplier(),
            'qso_score' => $config->calculateQsoScore(),
            'bonus_score' => $config->calculateBonusScore(),
            'gota_bonus' => $config->calculateGotaBonus() + $config->calculateGotaCoachBonus(),
            'has_gota' => $config->has_gota_station,
            'final_score' => $this->scoreBreakdown?->total ?? 0,
        ];
    }

    /**
     * The event's score as its pinned ruleset composes it, or null with no config.
     */
    #[Computed]
    public function scoreBreakdown(): ?ScoreBreakdown
    {
        return $this->config()?->scoreBreakdown();
    }

    /**
     * Ordered headline equation parts supplied by the ruleset, so Field Day
     * shows (QSO x Power) + Bonus and Winter Field Day shows QSO x (OM + 1).
     *
     * @return array<int, ScoreTerm|string>
     */
    #[Computed]
    public function scoringHeadline(): array
    {
        return $this->scoreBreakdown?->headline ?? [];
    }

    /**
     * The award wording this event's rulebook uses ("Bonus" vs "Objective").
     */
    #[Computed]
    public function terms(): Nomenclature
    {
        return $this->config()?->nomenclature() ?? new Nomenclature;
    }

    /**
     * Points a single contact in this mode is worth under the event's ruleset.
     *
     * Field Day reads modes.points_fd and Winter Field Day modes.points_wfd, so
     * the scoring key asks the ruleset rather than picking a column.
     */
    public function pointsForMode(Mode $mode): int
    {
        $config = $this->config();

        if (! $config) {
            return (int) ($mode->points_fd ?? 1);
        }

        return $config->pointsForContact($mode, Station::make(['is_gota' => false]));
    }

    #[Computed]
    public function bands(): Collection
    {
        $query = Band::allowedForFieldDay()->ordered();

        if ($this->config()) {
            $query->withUncommonBandsOnlyWhenWorked($this->config()->id);
        }

        return $query->get();
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
        $rulesVersion = $this->event->resolved_rules_version;

        $query = BonusType::where('is_active', true);

        if ($eventTypeId) {
            $query->where('event_type_id', $eventTypeId);
        }

        $query->where('rules_version', $rulesVersion);

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
            // Objective rulebooks award multipliers rather than points, so the
            // same tiles show summed OM instead of summed points.
            'verified_multiplier' => (int) $list->where('status', 'verified')
                ->sum(fn (array $item) => (int) $item['type']->objective_multiplier),
            'claimed_multiplier' => (int) $list->where('status', 'claimed')
                ->sum(fn (array $item) => (int) $item['type']->objective_multiplier),
        ];
    }

    #[Computed]
    public function availableRulesVersions(): array
    {
        $code = $this->event->eventType?->code;

        if ($code === null) {
            return [];
        }

        $versions = app(RuleSetFactory::class)->versionsFor($code);

        return collect($versions)
            ->map(fn (string $v) => ['id' => $v, 'name' => $v])
            ->values()
            ->all();
    }

    public function openRescoreModal(): void
    {
        abort_unless(auth()->user()?->isSystemAdmin(), 403);

        $this->rescoreTargetVersion = $this->event->resolved_rules_version;
        $this->showRescoreModal = true;
    }

    public function cancelRescore(): void
    {
        $this->showRescoreModal = false;
        $this->rescoreTargetVersion = '';
    }

    public function confirmRescore(RescoreService $rescorer): void
    {
        abort_unless(auth()->user()?->isSystemAdmin(), 403);

        $this->validate([
            'rescoreTargetVersion' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $target = $this->rescoreTargetVersion;
        $allowed = collect($this->availableRulesVersions)->pluck('id')->all();

        if (! in_array($target, $allowed, true)) {
            $this->addError('rescoreTargetVersion', 'Selected rules version is not available for this event type.');

            return;
        }

        $previous = $this->event->rules_version;

        Event::withoutRulesVersionLock(function () use ($target) {
            $this->event->rules_version = $target;
            $this->event->save();
        });

        $result = $rescorer->rescoreEvent($this->event->fresh());

        AuditLog::log(
            action: 'event.rules_rescored',
            auditable: $this->event,
            oldValues: ['rules_version' => $previous],
            newValues: [
                'rules_version' => $target,
                'contacts_rescored' => $result['rescored'],
                'contacts_unchanged' => $result['unchanged'],
                'bonuses_repointed' => $result['bonuses_repointed'],
                'bonuses_recomputed' => $result['bonuses_recomputed'],
                'bonuses_invalidated' => $result['bonuses_invalidated'],
                'bonuses_restored' => $result['bonuses_restored'],
            ],
            isCritical: true,
        );

        $this->event->refresh();

        $this->showRescoreModal = false;
        $this->rescoreTargetVersion = '';

        $bonusSummary = sprintf(
            '%d bonus %s moved to the selected rules, %d recalculated, %d invalidated',
            $result['bonuses_repointed'],
            str('claim')->plural($result['bonuses_repointed']),
            $result['bonuses_recomputed'],
            $result['bonuses_invalidated'],
        );

        if ($result['bonuses_restored'] > 0) {
            $bonusSummary .= sprintf(', %d restored', $result['bonuses_restored']);
        }

        $this->dispatch(
            'notify',
            title: 'Rescored',
            description: "Applied {$target} rules. Contacts updated: {$result['rescored']}. Unchanged: {$result['unchanged']}. {$bonusSummary}. Review your bonus and objective claims.",
        );
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
            session()->flash('toast', ['title' => 'Event Archived', 'description' => "Event '{$event->name}' has been archived because it has contacts.", 'type' => 'info']);
        } else {
            $event->forceDelete();
            session()->flash('toast', ['title' => 'Event Deleted', 'description' => "Event '{$event->name}' has been permanently deleted.", 'type' => 'success']);
        }

        $this->redirect(route('events.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.events.event-dashboard')->layout('layouts.app');
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'rescoreTargetVersion' => 'rules version',
        ];
    }
}
