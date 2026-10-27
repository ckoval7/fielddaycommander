<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Scoring\Exceptions\UnknownRuleSet;
use App\Services\RescoreService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rescore every event with GOTA contacts.
 *
 * GOTA contacts used to be stored at a flat 5 points and left out of QSO
 * points. ARRL scores them like the primary station's QSOs, with the 5-point
 * GOTA bonus on top, so they need repricing by mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        $configIds = DB::table('contacts')
            ->where('is_gota_contact', true)
            ->distinct()
            ->pluck('event_configuration_id')
            ->filter();

        if ($configIds->isEmpty()) {
            return;
        }

        $rescorer = app(RescoreService::class);

        Event::query()
            ->whereHas('eventConfiguration', fn ($query) => $query->whereIn('id', $configIds))
            ->get()
            ->each(function (Event $event) use ($rescorer) {
                try {
                    $rescorer->rescoreEvent($event);
                } catch (UnknownRuleSet) {
                    // An event on a rules version no longer shipped keeps its stored points.
                }
            });
    }

    public function down(): void
    {
        // The old flat GOTA rate was wrong; there is nothing to restore.
    }
};
