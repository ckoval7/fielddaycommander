<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field Day rule 3.2: a station that starts setup before 1800 UTC Saturday
 * may operate only 24 consecutive hours from its first contact. The index
 * serves the first-contact lookup behind Event::operatingEndsAt().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('setup_started_early')->default(false)->after('max_setup_hours');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['event_configuration_id', 'qso_time'], 'contacts_event_configuration_id_qso_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_event_configuration_id_qso_time_index');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('setup_started_early');
        });
    }
};
