<?php

namespace App\Services;

use App\Models\BonusType;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Scoring\Contracts\ImpliesObjectives;
use App\Scoring\RuleSetFactory;
use Carbon\CarbonInterface;

/**
 * Assembles the figures for the Winter Field Day "Event Summary" sheet.
 *
 * Mirrors the WFDA rules' Event Summary page: station identification, the
 * objective checklist, and the QSO point arithmetic. Every total comes from the
 * event's ruleset score so the sheet always agrees with the Scoring page.
 */
class WfdEventSummaryService
{
    public function __construct(protected RuleSetFactory $ruleSets) {}

    /**
     * @return array<string, mixed>
     */
    public function getData(EventConfiguration $config): array
    {
        $config->loadMissing(['section', 'operatingClass', 'event']);

        $rules = $this->ruleSets->forEvent($config->event);
        $score = $config->scoreBreakdown();

        $pointsByCategory = $config->creditableContacts()
            ->with('mode')
            ->get()
            ->groupBy(fn ($contact) => $contact->mode?->category === 'Phone' ? 'Phone' : 'CwDigital')
            ->map(fn ($group) => ['count' => $group->count(), 'points' => (int) $group->sum('points')]);

        $phone = $pointsByCategory->get('Phone', ['count' => 0, 'points' => 0]);
        $cwDigital = $pointsByCategory->get('CwDigital', ['count' => 0, 'points' => 0]);

        return [
            'callsign' => $config->callsign,
            'location' => $config->section?->code,
            'location_name' => $config->section?->name,
            'entry_class' => $config->transmitter_count.$config->operatingClass?->code,
            'club_name' => $config->club_name,
            'event_year' => $config->event->start_time->year,
            'objectives' => $this->objectives($config, $rules->eventTypeCode(), $rules->version(), $rules),
            'objective_multiplier' => $score->value('objective_multiplier'),
            'phone_contacts' => $phone['count'],
            'phone_points' => $phone['points'],
            'cw_digital_contacts' => $cwDigital['count'],
            'cw_digital_points' => $cwDigital['points'],
            'qso_points' => $score->value('qso_points'),
            'total_multiplier' => $score->value('total_multiplier'),
            'final_points' => $score->total,
            'no_credit_contacts' => $score->value('no_credit_contacts'),
            'bulletin' => $this->bulletinCopy($config),
            'rules_version' => $rules->version(),
            'generated_at' => now(),
        ];
    }

    /**
     * The saved bulletin copy, which the rules require be submitted with the log.
     *
     * @return array{name: string, text: string, frequency: string, mode: string, received_at: CarbonInterface}|null
     */
    protected function bulletinCopy(EventConfiguration $config): ?array
    {
        $bulletin = $config->w1awBulletin;

        if (! $bulletin) {
            return null;
        }

        return [
            'name' => $config->event->nomenclature()->bulletinName,
            'text' => $bulletin->bulletin_text,
            'frequency' => $bulletin->frequency,
            'mode' => $bulletin->mode,
            'received_at' => $bulletin->received_at,
        ];
    }

    /**
     * Every active objective for the event's rules, flagged as achieved when
     * claimed and verified or implied by another achieved objective.
     *
     * @return array<int, array{code: string, name: string, multiplier: int, achieved: bool}>
     */
    protected function objectives(EventConfiguration $config, string $eventTypeCode, string $version, object $rules): array
    {
        $types = BonusType::query()
            ->whereIn('event_type_id', EventType::query()->where('code', $eventTypeCode)->select('id'))
            ->where('rules_version', $version)
            ->whereNotNull('objective_multiplier')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $achievedCodes = $config->bonuses()
            ->where('is_verified', true)
            ->whereIn('bonus_type_id', $types->modelKeys())
            ->with('bonusType')
            ->get()
            ->pluck('bonusType.code')
            ->all();

        if ($rules instanceof ImpliesObjectives) {
            foreach ($rules->impliedObjectives() as $source => $implied) {
                if (in_array($source, $achievedCodes, true)) {
                    $achievedCodes = array_merge($achievedCodes, $implied);
                }
            }
        }

        return $types->map(fn (BonusType $type) => [
            'code' => $type->code,
            'name' => $type->name,
            'multiplier' => (int) $type->objective_multiplier,
            'achieved' => in_array($type->code, $achievedCodes, true),
        ])->all();
    }
}
