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
     * Put each entry on a net. Entries logged before nets existed go on one
     * net per event, named "Net".
     */
    public function up(): void
    {
        Schema::table('net_log_entries', function (Blueprint $table) {
            $table->foreignId('net_id')->nullable()->after('event_configuration_id')->constrained()->cascadeOnDelete();
        });

        $configurationIds = DB::table('net_log_entries')->distinct()->pluck('event_configuration_id');

        foreach ($configurationIds as $configurationId) {
            $netId = DB::table('nets')->insertGetId([
                'event_configuration_id' => $configurationId,
                'name' => 'Net',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('net_log_entries')
                ->where('event_configuration_id', $configurationId)
                ->update(['net_id' => $netId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('net_log_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('net_id');
        });
    }
};
