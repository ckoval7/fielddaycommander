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
        if (Schema::hasColumn('contacts', 'frequency_hz')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('frequency_hz')->nullable()->after('submode');
            $table->string('rst_sent', 4)->nullable()->after('power_watts');
            $table->string('rst_received', 4)->nullable()->after('rst_sent');
            $table->string('grid_square', 8)->nullable()->after('rst_received');
            $table->string('contact_name', 50)->nullable()->after('grid_square');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contacts', 'frequency_hz')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['frequency_hz', 'rst_sent', 'rst_received', 'grid_square', 'contact_name']);
        });
    }
};
