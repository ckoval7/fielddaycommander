<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Enums\PowerSource;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\User;
use Carbon\CarbonInterface;

class SubmissionReportService
{
    public function __construct(private ParticipantCountService $participantCounts) {}

    /**
     * Assemble all data needed to render the ARRL Field Day submission sheet PDF.
     *
     * @return array<string, mixed>
     */
    public function getData(EventConfiguration $config): array
    {
        $config->loadMissing(['section', 'operatingClass', 'event', 'bonuses.bonusType']);

        $bands = $this->bands($config);
        $modes = Mode::orderBy('id')->get();
        $bulletin = $this->bulletinCopy($config);

        // Power sources come from the event's flags and from the stations,
        // including the source recorded on each contact when it was made.
        $stationSources = $config->stationPowerSourcesUsed();
        $usesCommercial = $config->usesPowerSource(PowerSource::CommercialMains, $stationSources);
        $classCode = $config->operatingClass->code;

        return [
            // Section 1: Station Identification
            'callsign' => $config->callsign,
            'gota_callsign' => $config->gota_callsign,
            'club_name' => $config->club_name,
            'section' => $config->section->code,
            'section_name' => $config->section->name,
            'entry_class' => $config->transmitter_count.$classCode,
            // A Class A entry that used commercial mains for any contact is
            // listed as A-Commercial.
            'entry_category' => $classCode === 'A' && $usesCommercial ? 'A-Commercial' : null,
            'operating_class_code' => $classCode,
            'transmitter_count' => $config->transmitter_count,
            'participant_count' => $this->participantCounts->forConfiguration($config),

            // Section 2: Power Source (the ARRL form's five boxes; wind and
            // water go under Other)
            'uses_commercial_power' => $usesCommercial,
            'uses_generator' => $config->usesPowerSource(PowerSource::Generator, $stationSources),
            'uses_battery' => $config->usesPowerSource(PowerSource::Battery, $stationSources),
            'uses_solar' => $config->usesPowerSource(PowerSource::Solar, $stationSources),
            'uses_wind' => (bool) $config->uses_wind,
            'uses_water' => (bool) $config->uses_water,
            'uses_other_power' => $config->usesPowerSource(PowerSource::Other, $stationSources)
                || $config->uses_wind
                || $config->uses_water,

            // Section 3: Claimed Score
            'qso_base_points' => $config->qsoBasePoints(),
            'power_multiplier' => $config->calculatePowerMultiplier(),
            'qso_score' => $config->calculateQsoScore(),
            'bonus_score' => $config->calculateBonusScore(),
            'gota_bonus' => $config->calculateGotaBonus(),
            'gota_coach_bonus' => $config->calculateGotaCoachBonus(),
            'final_score' => $config->calculateFinalScore(),

            // Section 4: QSO Breakdown (band × mode grid)
            'bands' => $bands,
            'modes' => $modes,
            'band_mode_grid' => $this->bandModeGrid($config, $bands, $modes),
            'gota_mode_row' => $this->gotaModeRow($config),

            // Section 5: Bonus Points — full ARRL checklist
            'bonus_checklist' => $this->bonusChecklist($config),

            // Section 6: GOTA Station
            'has_gota_station' => $config->has_gota_station,
            'gota_contact_count' => $config->contacts()->where('is_gota_contact', true)->notDuplicate()->count(),
            'gota_operators' => $this->gotaOperatorRoster($config),

            // Operator List
            'operators' => $this->operatorList($config),

            // Bulletin copy, submitted with the entry
            'bulletin' => $bulletin,

            // Metadata
            'event_year' => $config->event->start_time->year,
            'event_start' => $config->event->start_time,
            'event_end' => $config->event->end_time,
            'generated_at' => now(),
            'total_pages' => 2 + (int) $config->has_gota_station + (int) ($bulletin !== null),
            'rules_version' => $config->event->resolved_rules_version,
        ];
    }

    /**
     * The saved bulletin copy, which the rules require be submitted with the log.
     *
     * @return array{name: string, text: string, frequency: string, mode: string, received_at: CarbonInterface}|null
     */
    private function bulletinCopy(EventConfiguration $config): ?array
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
     * Build the full ARRL bonus checklist in form order.
     *
     * Every ARRL-listed bonus appears, with claimed status and points from the DB.
     * Items not tracked in the DB appear unclaimed so the user knows to handle them manually.
     *
     * @return array<int, array{name: string, code: string, claimed: bool, points: int, is_verified: bool, auto: bool, note: string}>
     */
    private function bonusChecklist(EventConfiguration $config): array
    {
        // Canonical ARRL form order with display names matching the entry form
        $arrlOrder = [
            ['code' => 'emergency_power',         'name' => '100% emergency power'],
            ['code' => 'media_publicity',         'name' => 'Media publicity'],
            ['code' => 'public_location',         'name' => 'Public location'],
            ['code' => 'public_info_booth',       'name' => 'Public information table'],
            ['code' => 'sm_sec_message',          'name' => 'Formal message to ARRL SM/SEC'],
            ['code' => 'w1aw_bulletin',           'name' => 'W1AW Field Day message'],
            ['code' => 'nts_message',             'name' => 'Formal messages handled'],
            ['code' => 'satellite_qso',           'name' => 'Satellite QSO', 'auto' => true],
            ['code' => 'natural_power',           'name' => 'Natural power QSOs completed'],
            ['code' => 'elected_official_visit',  'name' => 'Elected official site visit'],
            ['code' => 'agency_visit',            'name' => 'Served agency site visit'],
            ['code' => 'educational_activity',    'name' => 'Educational activity'],
            ['code' => 'youth_participation',     'name' => 'Youth participation'],
            ['code' => '_gota',                   'name' => 'GOTA bonus', 'auto' => true],
            ['code' => 'web_submission',          'name' => 'Submitted entry online'],
            ['code' => 'safety_officer',          'name' => 'Safety officer'],
            ['code' => 'site_responsibilities',   'name' => 'Site responsibilities'],
            ['code' => 'social_media',            'name' => 'Social media'],
        ];

        // Index claimed bonuses by bonus_type code, scoped to the event's
        // resolved rules_version so stale cross-version rows do not leak in.
        $rulesVersion = $config->event?->resolved_rules_version;

        $claimed = [];
        foreach ($config->bonuses as $bonus) {
            $bonusType = $bonus->bonusType;

            if (! $bonusType || $bonusType->rules_version !== $rulesVersion) {
                continue;
            }

            $claimed[$bonusType->code] = [
                'name' => $bonusType->name,
                'points' => (int) $bonus->calculated_points,
                'is_verified' => $bonus->is_verified,
            ];
        }

        // Auto-determined bonuses replace any saved row for the same code, as
        // they do in the score: a computed 0 (emergency power lost to a
        // station on mains, say) leaves the bonus unclaimed.
        foreach ($this->autoBonusPoints($config) as $code => $points) {
            if ($points > 0) {
                $claimed[$code] = ['points' => $points, 'is_verified' => true];
            } else {
                unset($claimed[$code]);
            }
        }

        // Bonuses this entry class can't earn (site responsibilities on a
        // Class A sheet, say) are left off unless something was claimed.
        $bonusTypes = BonusType::query()
            ->where('event_type_id', $config->event?->event_type_id)
            ->where('rules_version', $rulesVersion)
            ->get()
            ->keyBy('code');

        $checklist = [];
        $rendered = [];
        foreach ($arrlOrder as $item) {
            $code = $item['code'];
            $match = $claimed[$code] ?? null;
            $rendered[$code] = true;
            $bonusType = $bonusTypes->get($code);

            if ($match === null && $bonusType && ! $config->isClassEligibleForBonus($bonusType)) {
                continue;
            }

            if ($match === null && $code === '_gota' && ! $config->has_gota_station) {
                continue;
            }

            $checklist[] = [
                'name' => $item['name'],
                'code' => $code,
                'claimed' => $match !== null,
                'points' => $match ? $match['points'] : 0,
                'is_verified' => $match ? $match['is_verified'] : false,
                'auto' => $item['auto'] ?? false,
            ];
        }

        // Append ruleset-specific extras (e.g. non-ARRL bonuses introduced by a
        // custom rules_version) so they are visible on the sheet.
        foreach ($claimed as $code => $match) {
            if (isset($rendered[$code])) {
                continue;
            }

            $checklist[] = [
                'name' => $match['name'] ?? $code,
                'code' => $code,
                'claimed' => true,
                'points' => $match['points'],
                'is_verified' => $match['is_verified'],
                'auto' => false,
            ];
        }

        return $checklist;
    }

    /**
     * Compute auto-determined bonus points keyed by canonical code.
     *
     * @return array<string, int>
     */
    private function autoBonusPoints(EventConfiguration $config): array
    {
        return [
            '_gota' => $config->calculateGotaBonus() + $config->calculateGotaCoachBonus(),
            'youth_participation' => $config->calculateYouthBonus(),
            'emergency_power' => $config->calculateEmergencyPowerBonus(),
            'satellite_qso' => $config->calculateSatelliteBonus(),
        ];
    }

    /**
     * Build the band × mode grid with QSO count and max power per cell.
     *
     * @return array<int, array<int, array{qsos: int, power: int}>>
     */
    private function bandModeGrid(EventConfiguration $config, array $bands, $modes): array
    {
        $counts = Contact::where('event_configuration_id', $config->id)
            ->notDuplicate()
            ->where('is_gota_contact', false)
            ->selectRaw('band_id, mode_id, count(*) as contact_count, max(power_watts) as max_power')
            ->groupBy('band_id', 'mode_id')
            ->get();

        $grid = [];
        foreach ($modes as $mode) {
            foreach ($bands as $band) {
                $entry = $counts->where('band_id', $band['id'])->where('mode_id', $mode->id)->first();
                $grid[$mode->id][$band['id']] = [
                    'qsos' => $entry ? (int) $entry->contact_count : 0,
                    'power' => $entry ? (int) $entry->max_power : 0,
                ];
            }
        }

        return $grid;
    }

    /**
     * GOTA station QSO count and max power per mode category, for the GOTA
     * row of the band/mode breakdown. GOTA QSOs earn QSO points like the
     * primary station's, and ARRL asks for them on their own row.
     *
     * @return array<string, array{qsos: int, power: int}>
     */
    private function gotaModeRow(EventConfiguration $config): array
    {
        $counts = Contact::query()
            ->where('contacts.event_configuration_id', $config->id)
            ->notDuplicate()
            ->where('is_gota_contact', true)
            ->join('modes', 'modes.id', '=', 'contacts.mode_id')
            ->selectRaw('modes.category as category, count(*) as contact_count, max(contacts.power_watts) as max_power')
            ->groupBy('modes.category')
            ->get()
            ->keyBy('category');

        $row = [];
        foreach (['CW', 'Digital', 'Phone'] as $category) {
            $entry = $counts->get($category);
            $row[$category] = [
                'qsos' => $entry ? (int) $entry->contact_count : 0,
                'power' => $entry ? (int) $entry->max_power : 0,
            ];
        }

        return $row;
    }

    /**
     * Bands on the ARRL form, plus LF and microwave bands only when the event has contacts on them.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function bands(EventConfiguration $config): array
    {
        $bandIdsWithQsos = Contact::where('event_configuration_id', $config->id)
            ->notDuplicate()
            ->where('is_gota_contact', false)
            ->distinct()
            ->pluck('band_id')
            ->all();

        return Band::allowedForFieldDay()
            ->ordered()
            ->get()
            ->filter(function (Band $b) use ($bandIdsWithQsos): bool {
                $frequency = $b->frequency_mhz === null ? null : (float) $b->frequency_mhz;
                $isOutsideFormBands = $frequency !== null && ($frequency < 1.8 || $frequency > 1300);

                return ! $isOutsideFormBands || in_array($b->id, $bandIdsWithQsos);
            })
            ->values()
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])
            ->all();
    }

    /**
     * Get all operator callsigns (for the operator list field).
     *
     * @return array<int, string>
     */
    private function operatorList(EventConfiguration $config): array
    {
        $userIds = Contact::where('event_configuration_id', $config->id)
            ->notDuplicate()
            ->whereNotNull('logger_user_id')
            ->distinct()
            ->pluck('logger_user_id');

        return User::whereIn('id', $userIds)
            ->excludeSystem()
            ->whereNotNull('call_sign')
            ->orderBy('call_sign')
            ->pluck('call_sign')
            ->all();
    }

    /**
     * Build GOTA operator roster with per-mode QSO counts and max power.
     *
     * Matches ARRL form: Name, Call, CW QSOs/Pwr, Digital QSOs/Pwr, Phone QSOs/Pwr.
     *
     * @return array<int, array{name: string, callsign: ?string, modes: array<string, array{qsos: int, power: int}>}>
     */
    private function gotaOperatorRoster(EventConfiguration $config): array
    {
        $contacts = Contact::where('event_configuration_id', $config->id)
            ->where('is_gota_contact', true)
            ->notDuplicate()
            ->with(['mode', 'gotaOperator'])
            ->get();

        $grouped = $contacts->groupBy(function ($c) {
            if ($c->gota_operator_user_id) {
                return 'user_'.$c->gota_operator_user_id;
            }

            return 'text_'.mb_strtolower(trim($c->gota_operator_first_name.' '.$c->gota_operator_last_name));
        });

        $roster = [];
        foreach ($grouped as $opContacts) {
            $first = $opContacts->first();

            if ($first->gota_operator_user_id && $first->gotaOperator) {
                $name = trim(($first->gotaOperator->first_name ?? '').' '.($first->gotaOperator->last_name ?? ''));
                $callsign = $first->gotaOperator->call_sign;
            } else {
                $name = trim(($first->gota_operator_first_name ?? '').' '.($first->gota_operator_last_name ?? ''));
                $callsign = $first->gota_operator_callsign;
            }

            $modes = [];
            foreach (['CW', 'Digital', 'Phone'] as $category) {
                $modeContacts = $opContacts->filter(fn ($c) => $c->mode?->category === $category);
                $modes[$category] = [
                    'qsos' => $modeContacts->count(),
                    'power' => $modeContacts->max('power_watts') ?? 0,
                ];
            }

            $roster[] = [
                'name' => $name,
                'callsign' => $callsign,
                'modes' => $modes,
                'total' => $opContacts->count(),
            ];
        }

        usort($roster, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $roster;
    }
}
