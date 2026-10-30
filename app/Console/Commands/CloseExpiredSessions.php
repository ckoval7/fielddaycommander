<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\OperatingSession;
use Illuminate\Console\Command;

class CloseExpiredSessions extends Command
{
    protected $signature = 'sessions:close-expired';

    protected $description = 'Close operating sessions for events that have ended';

    public function handle(): int
    {
        $staleSessions = OperatingSession::query()
            ->whereNull('end_time')
            ->whereHas('station.eventConfiguration.event', function ($query) {
                // Field Day events can stop operating before end_time (early
                // setup, or a class limited to 24 hours), so they are checked
                // against their operating end below.
                $query->where(fn ($ended) => $ended
                    ->where('end_time', '<', appNow())
                    ->orWhere('setup_started_early', true)
                    ->orWhereHas('eventType', fn ($type) => $type->where('code', 'FD')));
            })
            ->with('station.eventConfiguration.event.eventType', 'station.eventConfiguration.operatingClass', 'operator')
            ->get()
            ->filter(fn (OperatingSession $session) => $session->station->eventConfiguration->event->operatingEndsAt()?->lt(appNow()))
            ->values();

        if ($staleSessions->isEmpty()) {
            $this->info('No expired sessions found.');

            return self::SUCCESS;
        }

        foreach ($staleSessions as $session) {
            $eventEndTime = $session->station->eventConfiguration->event->operatingEndsAt();

            $session->update(['end_time' => $eventEndTime]);

            $operatorName = $session->operator?->call_sign ?? 'Unknown';
            $stationName = $session->station->name;
            $eventName = $session->station->eventConfiguration->event->name;

            $this->info("Closed session #{$session->id}: {$operatorName} at {$stationName} ({$eventName}) — end_time set to event end {$eventEndTime}");
        }

        $this->info("Closed {$staleSessions->count()} expired session(s).");

        return self::SUCCESS;
    }
}
