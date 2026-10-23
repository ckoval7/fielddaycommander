<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Advisory (never blocking) warnings shown while logging.
 *
 * Covers two rules shared by Field Day and Winter Field Day: contacts with
 * your own participants earn no credit, and only one transmitter may be on a
 * band-mode at a time.
 */
class LoggingWarningService
{
    private const PARTICIPANT_CACHE_SECONDS = 60;

    /**
     * Slash segments that are operating designators, not part of the identity.
     */
    private const DESIGNATOR_SEGMENTS = ['P', 'M', 'MM', 'AM', 'QRP', 'QRPP', 'A', 'B', 'R', 'T'];

    /**
     * Reduce a callsign to its base: uppercase, with portable/mobile/QRP-style
     * suffixes and single-digit area suffixes dropped, and for prefixed forms
     * (VE3/W1ABC) the longest remaining segment (the home call) kept.
     */
    public static function baseCallsign(string $callsign): string
    {
        $segments = array_values(array_filter(
            explode('/', strtoupper(trim($callsign))),
            fn (string $segment): bool => $segment !== ''
                && ! in_array($segment, self::DESIGNATOR_SEGMENTS, true)
                && ! ctype_digit($segment)
        ));

        if ($segments === []) {
            return strtoupper(trim($callsign));
        }

        usort($segments, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $segments[0];
    }

    /**
     * Base callsigns of everyone taking part in this event: the event and GOTA
     * callsigns, operators of its sessions, scheduled (not no-show) shift
     * signups, and guest GOTA operator callsigns. Cached briefly so it is not
     * rebuilt on every keystroke.
     *
     * @return array<int, string>
     */
    public function participantCallsigns(int $eventConfigurationId): array
    {
        return Cache::remember(
            "logging-participants.{$eventConfigurationId}",
            self::PARTICIPANT_CACHE_SECONDS,
            fn (): array => $this->buildParticipantCallsigns($eventConfigurationId)
        );
    }

    public function participantWarning(?string $callsign, int $eventConfigurationId): ?string
    {
        if ($callsign === null || trim($callsign) === '') {
            return null;
        }

        $base = self::baseCallsign($callsign);

        if (! in_array($base, $this->participantCallsigns($eventConfigurationId), true)) {
            return null;
        }

        return strtoupper(trim($callsign))." is a participant in this event. Contacts with your own participants earn no QSO credit.";
    }

    /**
     * Warn when another active, non-GOTA station is on the same band and mode
     * group (CW/Phone/Digital).
     */
    public function bandModeConflictWarning(Station $station, ?int $bandId, ?int $modeId): ?string
    {
        if ($station->is_gota || ! $bandId || ! $modeId) {
            return null;
        }

        $category = Mode::query()->whereKey($modeId)->value('category');
        if ($category === null) {
            return null;
        }

        $conflict = OperatingSession::query()
            ->active()
            ->where('station_id', '!=', $station->id)
            ->where('band_id', $bandId)
            ->whereHas('mode', fn ($query) => $query->where('category', $category))
            ->whereHas('station', fn ($query) => $query
                ->where('event_configuration_id', $station->event_configuration_id)
                ->where('is_gota', false))
            ->with(['station', 'band'])
            ->first();

        if (! $conflict) {
            return null;
        }

        $stationName = $conflict->station?->name ?? 'Another station';
        $bandName = $conflict->band?->name ?? 'this band';

        return "{$stationName} is also on {$bandName} {$category}. Only one transmitter per band/mode is allowed at a time.";
    }

    /**
     * @return array<int, string>
     */
    private function buildParticipantCallsigns(int $eventConfigurationId): array
    {
        $config = EventConfiguration::query()->find($eventConfigurationId);
        $callsigns = [$config?->callsign, $config?->gota_callsign];

        $sessionOperatorIds = OperatingSession::query()
            ->whereHas('station', fn ($query) => $query->where('event_configuration_id', $eventConfigurationId))
            ->pluck('operator_user_id');

        $shiftUserIds = ShiftAssignment::query()
            ->where('status', '!=', ShiftAssignment::STATUS_NO_SHOW)
            ->whereIn('shift_id', Shift::query()->where('event_configuration_id', $eventConfigurationId)->select('id'))
            ->pluck('user_id');

        $userCallsigns = User::query()
            ->whereIn('id', $sessionOperatorIds->merge($shiftUserIds)->unique()->all())
            ->where('call_sign', '!=', User::SYSTEM_CALL_SIGN)
            ->pluck('call_sign')
            ->all();

        $gotaOperatorCallsigns = Contact::query()
            ->where('event_configuration_id', $eventConfigurationId)
            ->whereNotNull('gota_operator_callsign')
            ->distinct()
            ->pluck('gota_operator_callsign')
            ->all();

        return collect([...$callsigns, ...$userCallsigns, ...$gotaOperatorCallsigns])
            ->filter(fn (?string $callsign): bool => $callsign !== null && trim($callsign) !== '')
            ->map(fn (string $callsign): string => self::baseCallsign($callsign))
            ->unique()
            ->values()
            ->all();
    }
}
