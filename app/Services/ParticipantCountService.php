<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The one participant count shown everywhere: the ARRL submission sheet, the
 * events list and the event dashboard.
 *
 * A participant is anyone who logged a non-duplicate contact, operated GOTA
 * (by account or by free-text name) or worked a volunteer shift (no-shows are
 * left out, as the volunteer report counts them). The SYSTEM user never counts.
 */
class ParticipantCountService
{
    /**
     * Count participants for a single event configuration.
     */
    public function forConfiguration(EventConfiguration $config): int
    {
        return $this->forConfigurations([$config->id])[$config->id];
    }

    /**
     * Count participants for many event configurations in a fixed number of
     * queries, so list pages don't run one set of queries per event.
     *
     * @param  iterable<int>  $configIds
     * @return array<int, int> participant count keyed by event configuration id
     */
    public function forConfigurations(iterable $configIds): array
    {
        $configIds = collect($configIds)->filter()->map(fn ($id): int => (int) $id)->unique()->values();

        if ($configIds->isEmpty()) {
            return [];
        }

        $systemUserIds = User::where('call_sign', User::SYSTEM_CALL_SIGN)->pluck('id');

        $loggers = Contact::query()
            ->whereIn('event_configuration_id', $configIds)
            ->notDuplicate()
            ->whereNotNull('logger_user_id')
            ->whereNotIn('logger_user_id', $systemUserIds)
            ->distinct()
            ->get(['event_configuration_id', 'logger_user_id as user_id']);

        $gotaOperators = Contact::query()
            ->whereIn('event_configuration_id', $configIds)
            ->where('is_gota_contact', true)
            ->notDuplicate()
            ->whereNotNull('gota_operator_user_id')
            ->whereNotIn('gota_operator_user_id', $systemUserIds)
            ->distinct()
            ->get(['event_configuration_id', 'gota_operator_user_id as user_id']);

        $gotaFreeText = Contact::query()
            ->whereIn('event_configuration_id', $configIds)
            ->where('is_gota_contact', true)
            ->notDuplicate()
            ->whereNull('gota_operator_user_id')
            ->whereNotNull('gota_operator_first_name')
            ->distinct()
            ->get(['event_configuration_id', 'gota_operator_first_name', 'gota_operator_last_name']);

        $volunteers = ShiftAssignment::query()
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->whereNull('shifts.deleted_at')
            ->whereIn('shifts.event_configuration_id', $configIds)
            ->where('shift_assignments.status', '!=', ShiftAssignment::STATUS_NO_SHOW)
            ->whereNotIn('shift_assignments.user_id', $systemUserIds)
            ->distinct()
            ->get(['shifts.event_configuration_id', 'shift_assignments.user_id']);

        $userIdsByConfig = $loggers->concat($gotaOperators)->concat($volunteers)
            ->groupBy('event_configuration_id')
            ->map(fn (Collection $rows): int => $rows->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->count());

        $freeTextByConfig = $gotaFreeText->countBy('event_configuration_id');

        return $configIds
            ->mapWithKeys(fn (int $id): array => [
                $id => (int) ($userIdsByConfig[$id] ?? 0) + (int) ($freeTextByConfig[$id] ?? 0),
            ])
            ->all();
    }
}
