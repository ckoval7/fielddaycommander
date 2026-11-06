<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give every contact a uuid, which is how relays identify contacts in the
 * feed. Transcribed contacts were stored without one. updated_at stays as it
 * is, so this doesn't look like a change to anything following the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('contacts')->whereNull('uuid')->select('id')->chunkById(500, function ($contacts): void {
            foreach ($contacts as $contact) {
                DB::table('contacts')->where('id', $contact->id)->update(['uuid' => Str::uuid()->toString()]);
            }
        });
    }

    public function down(): void
    {
        // The uuids do no harm if the app is rolled back.
    }
};
