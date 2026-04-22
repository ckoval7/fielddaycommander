<?php

namespace App\Scoring;

use App\Models\EventConfiguration;
use App\Scoring\Contracts\BonusStrategy;

class EventBonusReconciler
{
    public function __construct(private readonly RuleSetFactory $factory) {}

    /**
     * Run every strategy in the config's ruleset.
     */
    public function reconcileAll(EventConfiguration $config): void
    {
        foreach ($this->strategies($config) as $strategy) {
            $strategy->reconcile($config);
        }
    }

    /**
     * Run only the strategy matching $code. No-op if no strategy maps to it.
     */
    public function reconcileOne(EventConfiguration $config, string $code): void
    {
        foreach ($this->strategies($config) as $strategy) {
            if ($strategy->code() === $code) {
                $strategy->reconcile($config);

                return;
            }
        }
    }

    /**
     * @return array<int, BonusStrategy>
     */
    private function strategies(EventConfiguration $config): array
    {
        $event = $config->event;

        if (! $event) {
            return [];
        }

        $ruleset = $this->factory->forEvent($event);

        return array_map(
            fn (string $class): BonusStrategy => app($class),
            array_values($ruleset->strategies()),
        );
    }
}
