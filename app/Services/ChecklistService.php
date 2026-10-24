<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Enums\ChecklistScope;
use App\Events\ChecklistChanged;
use App\Models\AuditLog;
use App\Models\BonusType;
use App\Models\Checklist;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistItem;
use App\Models\ChecklistRun;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\User;
use App\Support\StationMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs checklists: who may answer them, recording answers, working out
 * when a run is complete, and keeping a checklist's bonus in step with it.
 */
class ChecklistService
{
    /**
     * Bonus change from the last answer: 'claimed', 'revoked' or null.
     */
    public const BONUS_CLAIMED = 'claimed';

    public const BONUS_REVOKED = 'revoked';

    /**
     * Find or start the run of a checklist for one subject.
     */
    public function runFor(Checklist $checklist, ?Station $station = null, ?User $user = null): ChecklistRun
    {
        [$key, $attributes] = match ($checklist->scope) {
            ChecklistScope::Station => [ChecklistRun::stationKey($station->id), ['station_id' => $station->id]],
            ChecklistScope::Person => [ChecklistRun::userKey($user->id), ['user_id' => $user->id]],
            ChecklistScope::Event => [ChecklistRun::EVENT_SUBJECT, []],
        };

        return ChecklistRun::createOrFirst(['checklist_id' => $checklist->id, 'subject_key' => $key], $attributes);
    }

    /**
     * Stations a per-station checklist covers.
     *
     * @return Collection<int, Station>
     */
    public function stationsFor(Checklist $checklist): Collection
    {
        return Station::forEvent($checklist->event_configuration_id)->orderBy('name')->get();
    }

    /**
     * People a per-person checklist covers: everyone with a shift at the
     * event, plus anyone who has already started their run.
     *
     * @return Collection<int, User>
     */
    public function peopleFor(Checklist $checklist): Collection
    {
        $startedIds = $checklist->runs()->whereNotNull('user_id')->pluck('user_id');

        return User::query()
            ->where(fn ($query) => $query
                ->whereHas('shiftAssignments', fn ($q) => $q
                    ->where('status', '!=', ShiftAssignment::STATUS_NO_SHOW)
                    ->whereHas('shift', fn ($s) => $s->where('event_configuration_id', $checklist->event_configuration_id)))
                ->orWhereIn('id', $startedIds))
            ->orderBy('call_sign')
            ->get(['id', 'call_sign', 'first_name', 'last_name']);
    }

    /**
     * How many subjects a checklist covers, and how many runs are complete.
     *
     * @return array{done: int, total: int}
     */
    public function progress(Checklist $checklist): array
    {
        $total = match ($checklist->scope) {
            ChecklistScope::Event => 1,
            ChecklistScope::Station => Station::forEvent($checklist->event_configuration_id)->count(),
            ChecklistScope::Person => $this->peopleFor($checklist)->count(),
        };

        $done = $checklist->runs()->whereNotNull('completed_at')
            ->when($checklist->scope === ChecklistScope::Station, fn ($q) => $q->whereHas('station'))
            ->count();

        return ['done' => min($done, $total), 'total' => $total];
    }

    /**
     * Item counts for a run.
     *
     * @return array{total: int, answered: int, required_total: int, required_answered: int}
     */
    public function counts(ChecklistRun $run): array
    {
        $items = $run->checklist->items()->get(['id', 'is_required']);
        $answeredIds = $run->answers()->whereNotNull('value')->pluck('checklist_item_id')->flip();

        return [
            'total' => $items->count(),
            'answered' => $items->filter(fn (ChecklistItem $item) => $answeredIds->has($item->id))->count(),
            'required_total' => $items->where('is_required', true)->count(),
            'required_answered' => $items->filter(fn (ChecklistItem $item) => $item->is_required && $answeredIds->has($item->id))->count(),
        ];
    }

    /**
     * Whether a user may answer a run.
     *
     * A checklist limited to shift roles needs a shift in one of them. Any
     * other checklist is open to schedule managers and anyone with a shift
     * at the event; a person's own checklist is always open to them, and a
     * station-mode operator may answer the bound station's checklists.
     */
    public function canAnswer(User $user, ChecklistRun $run): bool
    {
        $checklist = $run->checklist;
        $roleIds = $checklist->shiftRoles()->pluck('shift_roles.id');

        if (StationMode::isActive()) {
            return $roleIds->isEmpty()
                && $checklist->scope === ChecklistScope::Station
                && $run->station_id === StationMode::boundStationId();
        }

        if ($roleIds->isNotEmpty()) {
            return $this->hasShift($user, $checklist->event_configuration_id, $roleIds);
        }

        if ($checklist->scope === ChecklistScope::Person && $run->user_id === $user->id) {
            return true;
        }

        return $user->can('manage-shifts') || $this->hasShift($user, $checklist->event_configuration_id);
    }

    /**
     * Record an answer (null clears it) and return any bonus change.
     */
    public function answer(ChecklistRun $run, ChecklistItem $item, mixed $value, User $user): ?string
    {
        $normalized = $item->answer_type->normalize($value);

        $answer = ChecklistAnswer::firstOrNew(['checklist_run_id' => $run->id, 'checklist_item_id' => $item->id]);
        $answer->fill([
            'value' => $normalized,
            'answered_by_user_id' => $normalized === null ? null : $user->id,
            'answered_at' => $normalized === null ? null : appNow(),
        ])->save();

        AuditLog::log(
            action: 'checklist.item.answered',
            auditable: $answer,
            newValues: [
                'checklist' => $run->checklist->name,
                'subject' => $run->subjectName(),
                'label' => $item->label,
                'value' => $item->answer_type->display($normalized),
                'answered_by' => $normalized === null ? null : $user->call_sign,
            ],
        );

        $bonusChange = $this->refreshCompletion($run, $user);

        ChecklistChanged::dispatch($run->checklist->eventConfiguration->event_id, $run->checklist_id);

        return $bonusChange;
    }

    /**
     * Save the notes on an item.
     */
    public function saveNotes(ChecklistRun $run, ChecklistItem $item, ?string $notes): void
    {
        ChecklistAnswer::updateOrCreate(
            ['checklist_run_id' => $run->id, 'checklist_item_id' => $item->id],
            ['notes' => filled($notes) ? mb_substr(trim($notes), 0, 2000) : null],
        );
    }

    /**
     * Re-check a run against its completion rule, stamp or clear its
     * completion, and claim or revoke the checklist's bonus to match.
     */
    public function refreshCompletion(ChecklistRun $run, ?User $user = null): ?string
    {
        $counts = $this->counts($run);
        $isComplete = $run->checklist->completion_rule->isMet(
            $counts['total'], $counts['answered'], $counts['required_total'], $counts['required_answered'],
        );

        if ($isComplete && $run->completed_at === null) {
            $run->update(['completed_at' => appNow()]);
        } elseif (! $isComplete && $run->completed_at !== null) {
            $run->update(['completed_at' => null]);
        }

        if (! $run->checklist->earnsBonus()) {
            return null;
        }

        return $isComplete ? $this->claimBonus($run->checklist, $user) : $this->revokeBonus($run->checklist);
    }

    /**
     * Copy a checklist, with its items, onto another event. Shift role
     * limits carry over by role name.
     */
    public function copyTo(Checklist $source, EventConfiguration $target): Checklist
    {
        return DB::transaction(function () use ($source, $target) {
            $copy = $target->checklists()->create([
                ...$source->only(['name', 'description', 'scope', 'completion_rule', 'template_key']),
                'bonus_code' => $target->isScored() ? $source->bonus_code : null,
                'sort_order' => (int) $target->checklists()->max('sort_order') + 1,
            ]);

            foreach ($source->items as $item) {
                $copy->items()->create($item->only(['label', 'help_text', 'answer_type', 'is_required', 'is_default', 'sort_order']));
            }

            $roleNames = $source->shiftRoles()->pluck('name');

            if ($roleNames->isNotEmpty()) {
                $copy->shiftRoles()->sync($target->shiftRoles()->whereIn('name', $roleNames)->pluck('id'));
            }

            return $copy;
        });
    }

    /**
     * @param  Collection<int, int>|null  $shiftRoleIds
     */
    private function hasShift(User $user, int $eventConfigurationId, ?Collection $shiftRoleIds = null): bool
    {
        return ShiftAssignment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                ShiftAssignment::STATUS_SCHEDULED,
                ShiftAssignment::STATUS_CHECKED_IN,
                ShiftAssignment::STATUS_CHECKED_OUT,
            ])
            ->whereHas('shift', fn ($q) => $q
                ->where('event_configuration_id', $eventConfigurationId)
                ->when($shiftRoleIds !== null, fn ($q) => $q->whereIn('shift_role_id', $shiftRoleIds)))
            ->exists();
    }

    private function claimBonus(Checklist $checklist, ?User $user): ?string
    {
        $eventConfiguration = $checklist->eventConfiguration;
        $event = $eventConfiguration->event;
        $bonusType = $event ? BonusType::resolveFor($event, $checklist->bonus_code) : null;

        if (! $bonusType || ! $eventConfiguration->isClassEligibleForBonus($bonusType)) {
            return null;
        }

        // Already claimed (or claimed concurrently): the unique index makes
        // createOrFirst return the existing row instead of adding another.
        $bonus = EventBonus::createOrFirst([
            'event_configuration_id' => $eventConfiguration->id,
            'bonus_type_id' => $bonusType->id,
        ], [
            'claimed_by_user_id' => $user?->id,
            'quantity' => 1,
            'calculated_points' => $bonusType->base_points,
            'is_verified' => true,
            'verified_by_user_id' => $user?->id,
            'verified_at' => appNow(),
        ]);

        return $bonus->wasRecentlyCreated ? self::BONUS_CLAIMED : null;
    }

    /**
     * Remove the bonus a checklist earned, if it was claimed.
     */
    public function revokeBonus(Checklist $checklist): ?string
    {
        $event = $checklist->eventConfiguration->event;
        $bonusType = $event ? BonusType::resolveFor($event, $checklist->bonus_code) : null;

        if (! $bonusType) {
            return null;
        }

        $bonus = EventBonus::where('event_configuration_id', $checklist->event_configuration_id)
            ->where('bonus_type_id', $bonusType->id)
            ->first();

        if (! $bonus) {
            return null;
        }

        $bonus->delete();

        return self::BONUS_REVOKED;
    }
}
