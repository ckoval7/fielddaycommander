<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unscored events have no ARRL section or operating class.
     */
    public function up(): void
    {
        Schema::table('event_configurations', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable()->change();
            $table->unsignedBigInteger('operating_class_id')->nullable()->change();
        });
    }

    /**
     * Restore the contest fields as required.
     */
    public function down(): void
    {
        Schema::table('event_configurations', function (Blueprint $table) {
            $table->unsignedBigInteger('section_id')->nullable(false)->change();
            $table->unsignedBigInteger('operating_class_id')->nullable(false)->change();
        });
    }
};
