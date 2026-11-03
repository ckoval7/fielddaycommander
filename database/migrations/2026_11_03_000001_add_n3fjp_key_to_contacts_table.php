<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give contacts the record number N3FJP network clients know them by.
 *
 * N3FJP clients number records by counting additions in the order the server
 * relays them, so the N3FJP server assigns the numbers and keeps them here.
 * Only live contacts hold one; a deleted contact gives its number up. The
 * (event_configuration_id, updated_at) index serves the server's poll for
 * contacts changed in Field Day Commander.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedInteger('n3fjp_key')->nullable()->after('external_source');
            $table->unique(['event_configuration_id', 'n3fjp_key'], 'contacts_n3fjp_key_unique');
            $table->index(['event_configuration_id', 'updated_at'], 'contacts_event_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('contacts_n3fjp_key_unique');
            $table->dropIndex('contacts_event_updated_index');
            $table->dropColumn('n3fjp_key');
        });
    }
};
