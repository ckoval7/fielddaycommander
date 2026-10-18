<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Scoring\EventBonusReconciler;
use App\Scoring\Exceptions\UnknownRuleSet;
use App\Scoring\RuleSetFactory;
use Illuminate\Support\Collection;

class RescoreService
{
    /**
     * Callsigns re-settled per locked transaction during a rescore.
     */
    public int $callsignsPerChunk = 200;

    public function __construct(
        private readonly RuleSetFactory $factory,
        private readonly EventBonusReconciler $reconciler,
        private readonly DuplicateCheckService $dupes = new DuplicateCheckService,
    ) {}

    /**
     * Recompute `contacts.points` and re-point `event_bonuses` onto the
     * target version's bonus_types for every contact/claim on the given event.
     *
     * Call this AFTER persisting a new rules_version on the event.
     *
     * @return array{
     *     rescored: int,
     *     unchanged: int,
     *     bonuses_repointed: int,
     *     bonuses_recomputed: int,
     *     bonuses_invalidated: int,
     *     bonuses_restored: int,
     * }
     */
    public function rescoreEvent(Event $event): array
    {
        $config = $event->eventConfiguration;

        if (! $config) {
            return [
                'rescored' => 0,
                'unchanged' => 0,
                'bonuses_repointed' => 0,
                'bonuses_recomputed' => 0,
                'bonuses_invalidated' => 0,
                'bonuses_restored' => 0,
            ];
        }

        $typeCode = $event->eventType?->code;

        if ($typeCode === null || ! in_array((string) $event->rules_version, $this->factory->versionsFor($typeCode), true)) {
            throw UnknownRuleSet::for($typeCode ?? '(null)', (string) $event->rules_version);
        }

        $config->forgetResolvedRuleSet();
        $bonuses = ['repointed' => 0, 'recomputed' => 0, 'invalidated' => 0, 'restored' => 0];

        $total = Contact::query()->where('event_configuration_id', $config->id)->count();

        // Every dupe group is re-settled through DuplicateCheckService, the
        // same pricing, event-window and dupe logic live logging uses. It
        // takes the event lock per chunk of callsigns, so live logging is not
        // held off for the whole event.
        $rescored = $this->dupes->settleEvent($config->id, $this->callsignsPerChunk);
        $unchanged = max(0, $total - $rescored);

        // Same event lock as live logging, taken first, so the bonus pass and
        // a contact write serialize instead of deadlocking on each other's rows.
        $this->dupes->locked($config->id, function () use ($event, $config, &$bonuses) {
            $bonuses = $this->migrateEventBonuses($config, $event->rules_version);

            $this->reconciler->reconcileAll($config);
        });

        $config->forgetResolvedRuleSet();

        return [
            'rescored' => $rescored,
            'unchanged' => $unchanged,
            'bonuses_repointed' => $bonuses['repointed'],
            'bonuses_recomputed' => $bonuses['recomputed'],
            'bonuses_invalidated' => $bonuses['invalidated'],
            'bonuses_restored' => $bonuses['restored'],
        ];
    }

    /**
     * Re-point every event_bonus at the BonusType row for the target rules_version.
     *
     * - Matches by (event_type_id, rules_version, code).
     * - Recomputes calculated_points = quantity * base_points (clamped to max_points).
     * - If the target version has no matching code, the claim is invalidated
     *   (is_verified = false, calculated_points = 0) and surfaced in the count
     *   so the admin can re-examine it.
     *
     * @return array{repointed: int, recomputed: int, invalidated: int, restored: int}
     */
    private function migrateEventBonuses(EventConfiguration $config, string $targetVersion): array
    {
        $counts = ['repointed' => 0, 'recomputed' => 0, 'invalidated' => 0, 'restored' => 0];

        $eventTypeId = $config->event?->event_type_id;

        if (! $eventTypeId) {
            return $counts;
        }

        $targetByCode = BonusType::query()
            ->where('event_type_id', $eventTypeId)
            ->where('rules_version', $targetVersion)
            ->get()
            ->keyBy('code');

        EventBonus::query()
            ->where('event_configuration_id', $config->id)
            ->with('bonusType')
            ->chunkById(500, function ($bonuses) use ($targetByCode, &$counts) {
                foreach ($bonuses as $bonus) {
                    $this->migrateBonus($bonus, $targetByCode, $counts);
                }
            });

        return $counts;
    }

    /**
     * Apply migration to a single EventBonus, mutating $counts in place.
     *
     * @param  Collection<string, BonusType>  $targetByCode
     * @param  array{repointed: int, recomputed: int, invalidated: int, restored: int}  $counts
     */
    private function migrateBonus(EventBonus $bonus, $targetByCode, array &$counts): void
    {
        $currentCode = $bonus->bonusType?->code;

        if ($currentCode === null) {
            return;
        }

        $target = $targetByCode->get($currentCode);

        if (! $target) {
            $this->invalidateBonus($bonus, $counts);

            return;
        }

        $this->applyBonusTarget($bonus, $target, $counts);
    }

    /**
     * @param  array{repointed: int, recomputed: int, invalidated: int, restored: int}  $counts
     */
    private function invalidateBonus(EventBonus $bonus, array &$counts): void
    {
        if (! $bonus->is_verified && (int) $bonus->calculated_points === 0) {
            return;
        }

        // Only a claim the rescore itself un-verified may be restored later;
        // one already pending or rejected by a person stays as they left it.
        $bonus->invalidated_by_rescore = $bonus->is_verified || $bonus->invalidated_by_rescore;
        $bonus->is_verified = false;
        $bonus->calculated_points = 0;
        $bonus->save();
        $counts['invalidated']++;
    }

    /**
     * @param  array{repointed: int, recomputed: int, invalidated: int, restored: int}  $counts
     */
    private function applyBonusTarget(EventBonus $bonus, BonusType $target, array &$counts): void
    {
        $quantity = max(1, (int) ($bonus->quantity ?? 1));
        $newPoints = $quantity * (int) $target->base_points;

        if ($target->max_points !== null) {
            $newPoints = min($newPoints, (int) $target->max_points);
        }

        $repointedThis = $bonus->bonus_type_id !== $target->id;
        $recomputedThis = (int) $bonus->calculated_points !== $newPoints;
        $restoredThis = (bool) $bonus->invalidated_by_rescore;

        if (! $repointedThis && ! $recomputedThis && ! $restoredThis) {
            return;
        }

        $bonus->bonus_type_id = $target->id;
        $bonus->calculated_points = $newPoints;

        if ($restoredThis) {
            $bonus->is_verified = true;
            $bonus->invalidated_by_rescore = false;
            $counts['restored']++;
        }

        $bonus->save();

        if ($repointedThis) {
            $counts['repointed']++;
        }
        if ($recomputedThis) {
            $counts['recomputed']++;
        }
    }
}
