<?php

namespace App\Scoring;

use App\Models\Event;
use App\Scoring\Contracts\RuleSet;
use App\Scoring\Exceptions\UnknownRuleSet;
use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\FieldDay2026;
use App\Scoring\Rules\FieldDayTest;
use Illuminate\Support\Facades\Log;

class RuleSetFactory
{
    /**
     * Registry of (event_type_code => [rules_version => RuleSet class]).
     *
     * Add new versions here as they're implemented. DO NOT remove old entries —
     * historical events continue to resolve to their frozen rulesets.
     *
     * The synthetic FD-TEST ruleset is only registered in non-production
     * environments. It produces fabricated scores and must never be selectable
     * against real club data.
     *
     * @var array<string, array<string, class-string<RuleSet>>>
     */
    protected array $registry;

    /**
     * Memoized RuleSet instances keyed by "typeCode|version".
     *
     * @var array<string, RuleSet>
     */
    protected array $resolved = [];

    public function __construct()
    {
        $registry = [
            'FD' => [
                '2025' => FieldDay2025::class,
                '2026' => FieldDay2026::class,
            ],
        ];

        if (app()->environment(['local', 'testing'])) {
            $registry['FD']['TEST'] = FieldDayTest::class;
        }

        $this->registry = $registry;
    }

    public function forEvent(Event $event): RuleSet
    {
        $event->loadMissing('eventType');

        $typeCode = $event->eventType?->code
            ?? throw UnknownRuleSet::for('(null)', $event->effective_rules_version);

        $requested = $event->effective_rules_version;
        $key = $typeCode.'|'.$requested;

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        if (isset($this->registry[$typeCode][$requested])) {
            return $this->resolved[$key] = app($this->registry[$typeCode][$requested]);
        }

        // Soft fallback: pick the newest registered version for this event type.
        // Lets in-flight demo work with future-year events keep scoring until
        // the matching ruleset is shipped. Logs a warning so forgotten stubs
        // are still visible.
        $fallbackClass = $this->latestRegisteredFor($typeCode)
            ?? throw UnknownRuleSet::for($typeCode, $requested);

        $fallback = app($fallbackClass);

        Log::warning('Scoring rules_version fallback', [
            'event_id' => $event->id,
            'event_type' => $typeCode,
            'requested' => $requested,
            'resolved_to' => $fallback->version(),
        ]);

        return $this->resolved[$key] = $fallback;
    }

    /**
     * Registered rules versions for the given event type, oldest first.
     *
     * @return array<int, string>
     */
    public function versionsFor(string $typeCode): array
    {
        // array_keys coerces numeric-string keys like '2025' to ints, so cast
        // back to strings — version identifiers are strings throughout the app.
        $versions = array_map('strval', array_keys($this->registry[$typeCode] ?? []));
        sort($versions);

        return $versions;
    }

    /**
     * @return class-string<RuleSet>|null
     */
    protected function latestRegisteredFor(string $typeCode): ?string
    {
        $versions = $this->registry[$typeCode] ?? null;

        if (! $versions) {
            return null;
        }

        // Only numeric (year) versions are fallback candidates — synthetic
        // versions like 'TEST' must never be silently picked for an event
        // whose requested version isn't registered.
        $versions = array_filter(
            $versions,
            fn (string $version) => ctype_digit($version),
            ARRAY_FILTER_USE_KEY,
        );

        if (! $versions) {
            return null;
        }

        // ksort by version string — years are 4-char, string sort is year-correct.
        ksort($versions);

        return end($versions) ?: null;
    }
}
