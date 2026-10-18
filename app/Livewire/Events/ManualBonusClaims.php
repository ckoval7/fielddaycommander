<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Events;

use App\Livewire\Concerns\ResolvesBonusRule;
use App\Models\AuditLog;
use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\User;
use App\Scoring\Dto\Nomenclature;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ManualBonusClaims extends Component
{
    use ResolvesBonusRule;

    public Event $event;

    /** @var array<int, string> */
    public array $notes = [];

    /** @var array<int, int> */
    public array $quantities = [];

    public int $additionalYouth = 0;

    public function mount(): void
    {
        $this->additionalYouth = $this->youthStatus['additional'] ?? 0;
    }

    /**
     * The award wording this event's rulebook uses ("Bonus" vs "Objective").
     */
    #[Computed]
    public function terms(): Nomenclature
    {
        return $this->event->eventConfiguration?->nomenclature() ?? new Nomenclature;
    }

    #[Computed]
    public function eligibleBonusTypes(): Collection
    {
        $config = $this->event->eventConfiguration;

        if (! $config) {
            return collect();
        }

        $classCode = $config->operatingClass?->code;

        return BonusType::query()
            ->where('is_active', true)
            ->where('event_type_id', $this->event->event_type_id)
            ->where('rules_version', $this->event->resolved_rules_version)
            ->where('trigger_type', 'manual')
            ->get()
            ->filter(function (BonusType $bonusType) use ($classCode) {
                $classes = $bonusType->eligible_classes;

                if ($classes === null) {
                    return true;
                }

                if (is_string($classes)) {
                    $classes = json_decode($classes, true) ?? [];
                }

                return in_array($classCode, $classes);
            });
    }

    #[Computed]
    public function claimedBonuses(): Collection
    {
        $config = $this->event->eventConfiguration;

        if (! $config) {
            return collect();
        }

        return EventBonus::where('event_configuration_id', $config->id)
            ->whereIn('bonus_type_id', $this->eligibleBonusTypes->pluck('id'))
            ->get()
            ->keyBy('bonus_type_id');
    }

    public function claim(int $bonusTypeId, ?string $notes = null): void
    {
        if (! auth()->user()?->can('verify-bonuses')) {
            return;
        }

        $config = $this->event->eventConfiguration;

        if (! $config) {
            return;
        }

        // Only allow manual bonus types
        $bonusType = $this->eligibleBonusTypes->firstWhere('id', $bonusTypeId);

        if (! $bonusType) {
            return;
        }

        $quantity = 1;
        $points = $bonusType->base_points;

        if ($bonusType->is_per_occurrence && $bonusType->max_occurrences) {
            $quantity = min(
                max((int) ($this->quantities[$bonusTypeId] ?? 1), 1),
                $bonusType->max_occurrences
            );
            $points = $quantity * $bonusType->base_points;
        }

        // One claim per bonus type: a concurrent or repeated claim finds the
        // existing row (unique index) instead of adding a second one.
        $bonus = EventBonus::createOrFirst([
            'event_configuration_id' => $config->id,
            'bonus_type_id' => $bonusTypeId,
        ], [
            'claimed_by_user_id' => auth()->id(),
            'quantity' => $quantity,
            'calculated_points' => $points,
            'notes' => $notes,
            'is_verified' => true,
            'verified_by_user_id' => auth()->id(),
            'verified_at' => now(),
        ]);

        if (! $bonus->wasRecentlyCreated) {
            return;
        }

        AuditLog::log(
            action: 'bonus.claimed',
            auditable: $bonus,
            newValues: [
                'bonus_type' => $bonusType->code,
                'points' => $points,
                'quantity' => $quantity,
                'notes' => $notes,
            ]
        );

        unset($this->claimedBonuses);

        $this->dispatch('bonus-claimed');
    }

    public function unclaim(int $bonusTypeId): void
    {
        if (! auth()->user()?->can('verify-bonuses')) {
            return;
        }

        $config = $this->event->eventConfiguration;

        if (! $config) {
            return;
        }

        $bonusType = $this->eligibleBonusTypes->firstWhere('id', $bonusTypeId);

        if (! $bonusType) {
            return;
        }

        $bonus = EventBonus::where('event_configuration_id', $config->id)
            ->where('bonus_type_id', $bonusTypeId)
            ->first();

        if (! $bonus) {
            return;
        }

        AuditLog::log(
            action: 'bonus.unclaimed',
            auditable: $bonus,
            oldValues: [
                'bonus_type' => $bonusType->code,
                'points' => $bonus->calculated_points,
            ]
        );

        $bonus->delete();

        unset($this->claimedBonuses);

        $this->dispatch('bonus-claimed');
    }

    /**
     * Get youth participation status computed from DB queries.
     *
     * @return array{auto_count: int, additional: int, total: int, points: int, max_points: int}|null
     */
    #[Computed]
    public function youthStatus(): ?array
    {
        $config = $this->event->eventConfiguration;

        if (! $config) {
            return null;
        }

        $bonusType = BonusType::resolveFor($this->event, 'youth_participation');

        if (! $bonusType) {
            return null;
        }

        // Check class eligibility
        $classCode = $config->operatingClass?->code;
        $classes = $bonusType->eligible_classes;

        if ($classes !== null) {
            if (is_string($classes)) {
                $classes = json_decode($classes, true) ?? [];
            }
            if (! in_array($classCode, $classes)) {
                return null;
            }
        }

        $autoCount = $config->countYouthWithQsos();

        $bonus = EventBonus::where('event_configuration_id', $config->id)
            ->where('bonus_type_id', $bonusType->id)
            ->first();

        $additional = $bonus ? (int) ($bonus->manual_quantity_adjustment ?? 0) : 0;

        $total = min($autoCount + $additional, $bonusType->max_occurrences ?? 5);

        return [
            'auto_count' => $autoCount,
            'additional' => $additional,
            'total' => $total,
            'points' => $total * $bonusType->base_points,
            'max_points' => $bonusType->max_points ?? 100,
        ];
    }

    /**
     * Operators who logged a non-duplicate contact this event, with their youth flag.
     *
     * Covers members and ghost accounts created at station-mode sign-in, by
     * external loggers or by ADIF import, so an event manager can flag youth
     * without user-management access.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function eventOperators(): Collection
    {
        $config = $this->event->eventConfiguration;

        if (! $config || ! $this->youthStatus) {
            return collect();
        }

        $operatorIds = $config->contacts()->notDuplicate()->whereNotNull('logger_user_id')->distinct()->pluck('logger_user_id')
            ->merge($config->contacts()->notDuplicate()->where('is_gota_contact', true)->whereNotNull('gota_operator_user_id')->distinct()->pluck('gota_operator_user_id'))
            ->unique();

        return User::query()
            ->whereIn('id', $operatorIds)
            ->excludeSystem()
            ->orderBy('call_sign')
            ->get(['id', 'call_sign', 'first_name', 'last_name', 'is_youth', 'is_ghost']);
    }

    /**
     * Flag or unflag an operator who logged this event as youth (age 18 or younger).
     *
     * Flagging someone while the no-account count is above zero moves one
     * youth off that count, since that person was most likely entered there
     * before their account was flagged. The manager can raise it again.
     */
    public function toggleOperatorYouth(int $userId): void
    {
        if (! auth()->user()?->can('verify-bonuses')) {
            return;
        }

        $operator = $this->eventOperators->firstWhere('id', $userId);

        if (! $operator) {
            return;
        }

        $isYouth = ! $operator->is_youth;
        $operator->update(['is_youth' => $isYouth]);

        AuditLog::log(
            action: 'user.youth_flag_changed',
            auditable: $operator,
            oldValues: ['is_youth' => ! $isYouth],
            newValues: ['is_youth' => $isYouth],
        );

        unset($this->youthStatus);
        $status = $this->youthStatus;

        if ($isYouth && $status && $status['additional'] > 0) {
            $this->additionalYouth = $status['additional'] - 1;
            $this->dispatch('toast',
                title: 'Youth count adjusted',
                description: "Moved one youth from the no-account count to {$operator->call_sign}.",
                icon: 'phosphor-info',
                css: 'alert-info'
            );
        }

        unset($this->eventOperators);
        $this->saveAdditionalYouth();
    }

    public function saveAdditionalYouth(): void
    {
        if (! auth()->user()?->can('verify-bonuses')) {
            return;
        }

        $config = $this->event->eventConfiguration;

        if (! $config) {
            return;
        }

        $bonusType = BonusType::resolveFor($this->event, 'youth_participation');

        if (! $bonusType) {
            return;
        }

        $additional = max(0, $this->additionalYouth);
        $autoCount = $config->countYouthWithQsos();
        $total = min($autoCount + $additional, $bonusType->max_occurrences ?? 5);

        if ($total > 0) {
            EventBonus::updateOrCreate(
                [
                    'event_configuration_id' => $config->id,
                    'bonus_type_id' => $bonusType->id,
                ],
                [
                    'quantity' => $total,
                    'calculated_points' => $total * $bonusType->base_points,
                    'manual_quantity_adjustment' => $additional > 0 ? $additional : null,
                    'is_verified' => true,
                    'invalidated_by_rescore' => false,
                    'verified_by_user_id' => auth()->id(),
                    'verified_at' => now(),
                ]
            );

            $bonus = EventBonus::where('event_configuration_id', $config->id)
                ->where('bonus_type_id', $bonusType->id)
                ->first();

            if ($bonus) {
                AuditLog::log(
                    action: 'bonus.claimed',
                    auditable: $bonus,
                    newValues: [
                        'bonus_type' => 'youth_participation',
                        'points' => $bonus->calculated_points,
                        'quantity' => $bonus->quantity,
                    ]
                );
            }
        } else {
            $existingBonus = EventBonus::where('event_configuration_id', $config->id)
                ->where('bonus_type_id', $bonusType->id)
                ->first();

            if ($existingBonus) {
                AuditLog::log(
                    action: 'bonus.unclaimed',
                    auditable: $existingBonus,
                    oldValues: [
                        'bonus_type' => 'youth_participation',
                        'points' => $existingBonus->calculated_points,
                    ]
                );
                $existingBonus->delete();
            }
        }

        unset($this->youthStatus);
        $this->dispatch('bonus-claimed');
    }

    public function render(): View
    {
        return view('livewire.events.manual-bonus-claims');
    }
}
