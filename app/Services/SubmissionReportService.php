<?php

namespace App\Services;

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\User;

class SubmissionReportService
{
    /**
     * Assemble all data needed to render the ARRL Field Day submission sheet PDF.
     *
     * @return array<string, mixed>
     */
    public function getData(EventConfiguration $config): array
    {
        $config->loadMissing(['section', 'operatingClass', 'event', 'bonuses.bonusType']);

        $bands = $this->bands();
        $modes = Mode::orderBy('id')->get();

        return [
            // Section 1: Station Identification
            'callsign' => $config->callsign,
            'gota_callsign' => $config->gota_callsign,
            'club_name' => $config->club_name,
            'section' => $config->section->code,
            'section_name' => $config->section->name,
            'entry_class' => $config->transmitter_count.$config->operatingClass->code,
            'operating_class_code' => $config->operatingClass->code,
            'transmitter_count' => $config->transmitter_count,
            'participant_count' => $this->participantCount($config),

            // Section 2: Power Source
            'uses_commercial_power' => $config->uses_commercial_power,
            'uses_generator' => $config->uses_generator,
            'uses_battery' => $config->uses_battery,
            'uses_solar' => $config->uses_solar,
            'uses_wind' => $config->uses_wind,
            'uses_water' => $config->uses_water,
            'uses_other_power' => $config->uses_other_power,

            // Section 3: Claimed Score
            'qso_base_points' => (int) $config->contacts()->notDuplicate()->where('is_gota_contact', false)->sum('points'),
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

            // Section 5: Bonus Points — full ARRL checklist
            'bonus_checklist' => $this->bonusChecklist($config),

            // Section 6: GOTA Station
            'has_gota_station' => $config->has_gota_station,
            'gota_contact_count' => $config->contacts()->where('is_gota_contact', true)->notDuplicate()->count(),
            'gota_operators' => $this->gotaOperatorRoster($config),

            // Operator List
            'operators' => $this->operatorList($config),

            // Metadata
            'event_year' => $config->event->start_time->year,
            'event_start' => $config->event->start_time,
            'event_end' => $config->event->end_time,
            'generated_at' => now(),
        ];
    }

    /**
     * Count unique participants (operators + GOTA operators), excluding the SYSTEM user.
     */
    private function participantCount(EventConfiguration $config): int
    {
        $systemUserIds = User::where('call_sign', User::SYSTEM_CALL_SIGN)->pluck('id');

        $loggerIds = Contact::where('event_configuration_id', $config->id)
            ->notDuplicate()
            ->whereNotNull('logger_user_id')
            ->whereNotIn('logger_user_id', $systemUserIds)
            ->distinct()
            ->pluck('logger_user_id');

        $gotaUserIds = Contact::where('event_configuration_id', $config->id)
            ->where('is_gota_contact', true)
            ->notDuplicate()
            ->whereNotNull('gota_operator_user_id')
            ->whereNotIn('gota_operator_user_id', $systemUserIds)
            ->distinct()
            ->pluck('gota_operator_user_id');

        $gotaFreeTextCount = Contact::where('event_configuration_id', $config->id)
            ->where('is_gota_contact', true)
            ->notDuplicate()
            ->whereNull('gota_operator_user_id')
            ->whereNotNull('gota_operator_first_name')
            ->selectRaw('DISTINCT gota_operator_first_name, gota_operator_last_name')
            ->get()
            ->count();

        return $loggerIds->merge($gotaUserIds)->unique()->count() + $gotaFreeTextCount;
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

        // Index claimed bonuses by bonus_type code
        $claimed = [];
        foreach ($config->bonuses as $bonus) {
            $code = $bonus->bonusType?->code;
            if ($code) {
                $claimed[$code] = [
                    'points' => (int) $bonus->calculated_points,
                    'is_verified' => $bonus->is_verified,
                ];
            }
        }

        // Auto-determined bonuses
        $gotaBonus = $config->calculateGotaBonus() + $config->calculateGotaCoachBonus();
        if ($gotaBonus > 0) {
            $claimed['_gota'] = ['points' => $gotaBonus, 'is_verified' => true];
        }

        $checklist = [];
        foreach ($arrlOrder as $item) {
            $code = $item['code'];
            $match = $claimed[$code] ?? null;

            $checklist[] = [
                'name' => $item['name'],
                'code' => $code,
                'claimed' => $match !== null,
                'points' => $match ? $match['points'] : 0,
                'is_verified' => $match ? $match['is_verified'] : false,
                'auto' => $item['auto'] ?? false,
            ];
        }

        return $checklist;
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
     * @return array<int, array{id: int, name: string}>
     */
    private function bands(): array
    {
        return Band::allowedForFieldDay()
            ->ordered()
            ->get()
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
