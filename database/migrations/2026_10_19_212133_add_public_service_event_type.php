<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the unscored Public Service event type.
     */
    public function up(): void
    {
        // Fresh installs get the type from EventTypeSeeder; only add it here
        // to installs that have already been seeded.
        if (! DB::table('event_types')->exists()) {
            return;
        }

        DB::table('event_types')->insertOrIgnore([
            'code' => 'PS',
            'name' => 'Public Service / Other',
            'description' => 'Public service, EmComm drills, nets and other events that are not scored as a contest',
            'is_active' => true,
            'setup_offset_hours' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Remove the Public Service event type.
     */
    public function down(): void
    {
        DB::table('event_types')->where('code', 'PS')->delete();
    }
};
