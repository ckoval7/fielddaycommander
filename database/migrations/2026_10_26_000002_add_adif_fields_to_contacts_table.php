<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('contacts', 'adif_fields')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->json('adif_fields')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contacts', 'adif_fields')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('adif_fields');
        });
    }
};
