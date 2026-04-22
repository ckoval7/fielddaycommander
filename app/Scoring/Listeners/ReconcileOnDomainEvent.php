<?php

namespace App\Scoring\Listeners;

use App\Models\EventConfiguration;
use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\RuleSetFactory;
use Illuminate\Contracts\Container\Container;

class ReconcileOnDomainEvent
{
    public function __construct(
        private readonly RuleSetFactory $factory,
        private readonly Container $container,
    ) {}

    public function handle(object $event): void
    {
        $configId = $event->eventConfigurationId ?? null;

        if (! $configId) {
            return;
        }

        $config = EventConfiguration::with('event')->find($configId);

        if (! $config?->event) {
            return;
        }

        $ruleset = $this->factory->forEvent($config->event);

        foreach ($ruleset->strategies() as $class) {
            /** @var BonusStrategy $strategy */
            $strategy = $this->container->make($class);

            if (in_array($event::class, $strategy->subscribesTo(), true)) {
                $strategy->reconcile($config);
            }
        }
    }
}
