<?php

namespace App\Livewire\Concerns;

use App\Models\Event;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\RuleSetFactory;

/**
 * Exposes `bonusRule(code)` to Livewire views so the rulebook reference for a
 * bonus is resolved from the event's versioned RuleSet, not a hardcoded map.
 *
 * Consuming components must expose a `?Event $event` property.
 */
trait ResolvesBonusRule
{
    protected ?RuleSet $resolvedRuleSet = null;

    /**
     * @return array{section: string, text: string}|null
     */
    public function bonusRule(string $code): ?array
    {
        /** @var ?Event $event */
        $event = $this->event ?? null;

        if (! $event) {
            return null;
        }

        $this->resolvedRuleSet ??= app(RuleSetFactory::class)->forEvent($event);

        return $this->resolvedRuleSet->bonusRuleReference($code);
    }
}
