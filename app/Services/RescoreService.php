<?php

namespace App\Services;

use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\EventBonusReconciler;
use App\Scoring\RuleSetFactory;
use Illuminate\Support\Facades\DB;

class RescoreService
{
    public function __construct(
        private readonly RuleSetFactory $factory,
        private readonly EventBonusReconciler $reconciler,
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
            ];
        }

        $ruleSet = $this->factory->forEvent($event);

        $rescored = 0;
        $unchanged = 0;
        $bonuses = ['repointed' => 0, 'recomputed' => 0, 'invalidated' => 0];

        DB::transaction(function () use ($event, $config, $ruleSet, &$rescored, &$unchanged, &$bonuses) {
            Contact::query()
                ->where('event_configuration_id', $config->id)
                ->with(['mode', 'operatingSession.station'])
                ->chunkById(500, function ($contacts) use ($ruleSet, &$rescored, &$unchanged) {
                    foreach ($contacts as $contact) {
                        $newPoints = $this->pointsFor($contact, $ruleSet);
                        $oldPoints = (int) $contact->points;

                        if ($newPoints === $oldPoints) {
                            $unchanged++;

                            continue;
                        }

                        $contact->points = $newPoints;
                        $contact->saveQuietly();
                        $rescored++;
                    }
                });

            $bonuses = $this->migrateEventBonuses($config, $event->rules_version);

            $this->reconciler->reconcileAll($config);
        });

        $this->resetMemoizedRuleSet($config);

        return [
            'rescored' => $rescored,
            'unchanged' => $unchanged,
            'bonuses_repointed' => $bonuses['repointed'],
            'bonuses_recomputed' => $bonuses['recomputed'],
            'bonuses_invalidated' => $bonuses['invalidated'],
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
     * @return array{repointed: int, recomputed: int, invalidated: int}
     */
    private function migrateEventBonuses(EventConfiguration $config, string $targetVersion): array
    {
        $eventTypeId = $config->event?->event_type_id;

        if (! $eventTypeId) {
            return ['repointed' => 0, 'recomputed' => 0, 'invalidated' => 0];
        }

        $targetByCode = BonusType::query()
            ->where('event_type_id', $eventTypeId)
            ->where('rules_version', $targetVersion)
            ->get()
            ->keyBy('code');

        $repointed = 0;
        $recomputed = 0;
        $invalidated = 0;

        EventBonus::query()
            ->where('event_configuration_id', $config->id)
            ->with('bonusType')
            ->chunkById(500, function ($bonuses) use ($targetByCode, &$repointed, &$recomputed, &$invalidated) {
                foreach ($bonuses as $bonus) {
                    $currentCode = $bonus->bonusType?->code;

                    if ($currentCode === null) {
                        continue;
                    }

                    $target = $targetByCode->get($currentCode);

                    if (! $target) {
                        if ($bonus->is_verified || (int) $bonus->calculated_points !== 0) {
                            $bonus->is_verified = false;
                            $bonus->calculated_points = 0;
                            $bonus->save();
                            $invalidated++;
                        }

                        continue;
                    }

                    $quantity = max(1, (int) ($bonus->quantity ?? 1));
                    $newPoints = $quantity * (int) $target->base_points;

                    if ($target->max_points !== null) {
                        $newPoints = min($newPoints, (int) $target->max_points);
                    }

                    $repointedThis = $bonus->bonus_type_id !== $target->id;
                    $recomputedThis = (int) $bonus->calculated_points !== $newPoints;

                    if (! $repointedThis && ! $recomputedThis) {
                        continue;
                    }

                    $bonus->bonus_type_id = $target->id;
                    $bonus->calculated_points = $newPoints;
                    $bonus->save();

                    if ($repointedThis) {
                        $repointed++;
                    }
                    if ($recomputedThis) {
                        $recomputed++;
                    }
                }
            });

        return [
            'repointed' => $repointed,
            'recomputed' => $recomputed,
            'invalidated' => $invalidated,
        ];
    }

    private function pointsFor(Contact $contact, RuleSet $ruleSet): int
    {
        if ($contact->is_duplicate) {
            return 0;
        }

        $mode = $contact->mode;
        $station = $contact->operatingSession?->station;

        if ($mode === null || $station === null) {
            return (int) $contact->points;
        }

        return $ruleSet->pointsForContact($mode, $station);
    }

    private function resetMemoizedRuleSet(EventConfiguration $config): void
    {
        $property = new \ReflectionProperty($config, 'resolvedRuleSet');
        $property->setValue($config, null);
    }
}
