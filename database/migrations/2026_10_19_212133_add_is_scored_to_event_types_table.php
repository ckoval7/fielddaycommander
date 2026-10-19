<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the scored flag and the unscored Public Service event type.
     */
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->boolean('is_scored')->default(true)->after('is_active');
        });

        DB::table('event_types')->insertOrIgnore([
            'code' => 'PS',
            'name' => 'Public Service / Other',
            'description' => 'Public service, EmComm drills, nets and other events that are not scored as a contest',
            'is_active' => true,
            'is_scored' => false,
            'setup_offset_hours' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Remove the Public Service event type and the scored flag.
     */
    public function down(): void
    {
        DB::table('event_types')->where('code', 'PS')->delete();

        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn('is_scored');
        });
    }
};
