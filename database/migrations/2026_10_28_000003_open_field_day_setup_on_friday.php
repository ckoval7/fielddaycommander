<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ARRL Field Day rule 3.3 opens setup at 0000 UTC on the Friday before the
 * event, 42 hours before the usual 1800 UTC Saturday start; it had been
 * opening 24 hours before the start. Fixes the event type and every Field
 * Day event that hasn't started yet. Past events keep what they had.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setFieldDaySetup(42, fn (Carbon $start): Carbon => $start->copy()->utc()->previous(Carbon::FRIDAY));
    }

    public function down(): void
    {
        $this->setFieldDaySetup(24, fn (Carbon $start): Carbon => $start->copy()->subHours(24));
    }

    /**
     * @param  Closure(Carbon): Carbon  $setupFrom
     */
    private function setFieldDaySetup(int $offsetHours, Closure $setupFrom): void
    {
        $typeId = DB::table('event_types')->where('code', 'FD')->value('id');

        if ($typeId === null) {
            return;
        }

        DB::table('event_types')->where('id', $typeId)->update(['setup_offset_hours' => $offsetHours]);

        DB::table('events')
            ->where('event_type_id', $typeId)
            ->whereNotNull('start_time')
            ->where('start_time', '>', now())
            ->get(['id', 'start_time'])
            ->each(fn (object $event) => DB::table('events')
                ->where('id', $event->id)
                ->update(['setup_allowed_from' => $setupFrom(Carbon::parse($event->start_time, 'UTC'))]));
    }
};
