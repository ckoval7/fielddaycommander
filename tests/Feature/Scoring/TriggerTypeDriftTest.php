<?php

use App\Models\BonusType;
use App\Models\EventType;
use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\RuleSetFactory;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

uses()->group('feature', 'scoring');

/**
 * Guards against drift between:
 *   - strategies declared in a RuleSet (strategy->triggerType())
 *   - the trigger_type column on the seeded bonus_types row
 *
 * A mismatch is invisible at runtime: the UI silently hides the bonus (if it
 * filters by trigger_type='manual') and/or the reconciler never fires.
 *
 * Versions whose BonusType rows haven't been seeded yet are skipped so a new
 * ruleset class can land before its seed migration without breaking CI; they
 * get covered automatically the moment their rows exist.
 */
test('strategy triggerType matches seeded BonusType.trigger_type for every registered ruleset', function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $factory = app(RuleSetFactory::class);
    $checked = 0;

    foreach (['FD'] as $typeCode) {
        $eventTypeId = EventType::query()->where('code', $typeCode)->value('id');
        expect($eventTypeId)->not->toBeNull();

        foreach ($factory->versionsFor($typeCode) as $version) {
            if (! ctype_digit($version)) {
                continue; // Synthetic versions like 'TEST' have no production seed.
            }

            $hasSeed = BonusType::query()
                ->where('event_type_id', $eventTypeId)
                ->where('rules_version', $version)
                ->exists();

            if (! $hasSeed) {
                continue; // Ruleset shipped; seed migration not yet applied.
            }

            /** @var RuleSet $ruleset */
            $ruleset = app()->make(strategies_for_version_class($factory, $typeCode, $version));

            foreach ($ruleset->strategies() as $code => $strategyClass) {
                /** @var BonusStrategy $strategy */
                $strategy = app($strategyClass);

                $bonusType = BonusType::query()
                    ->where('event_type_id', $eventTypeId)
                    ->where('rules_version', $version)
                    ->where('code', $code)
                    ->first();

                expect($bonusType)->not->toBeNull("no seeded BonusType for {$code} @ {$typeCode}-{$version}")
                    ->and($bonusType->trigger_type)->toBe(
                        $strategy->triggerType(),
                        "trigger_type mismatch for {$code} @ {$typeCode}-{$version}: "
                        ."DB={$bonusType->trigger_type}, strategy={$strategy->triggerType()}"
                    );

                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, 'no registered ruleset had seeded BonusType rows to check against');
});

/**
 * Reflectively read the RuleSet class for (type, version) from the factory's
 * internal registry. Kept local to this test so the factory doesn't need a
 * public accessor just for drift checks.
 */
function strategies_for_version_class(RuleSetFactory $factory, string $typeCode, string $version): string
{
    $reflection = new ReflectionClass($factory);
    $property = $reflection->getProperty('registry');
    /** @var array<string, array<string, class-string<RuleSet>>> $registry */
    $registry = $property->getValue($factory);

    return $registry[$typeCode][$version];
}
