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
                $query->where('end_time', '<', appNow());
            })
            ->with('station.eventConfiguration.event', 'operator')
            ->get();

        if ($staleSessions->isEmpty()) {
            $this->info('No expired sessions found.');

            return self::SUCCESS;
        }

        foreach ($staleSessions as $session) {
            $eventEndTime = $session->station->eventConfiguration->event->end_time;

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
