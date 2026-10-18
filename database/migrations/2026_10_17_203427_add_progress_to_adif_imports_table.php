<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADIF validation and import run on the queue: the import row carries their
 * progress for the wizard to poll, and each imported record points at the
 * contact it created so an interrupted import can resume where it stopped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adif_imports', function (Blueprint $table) {
            $table->unsignedInteger('progress_processed')->default(0)->after('merged_records');
            $table->unsignedInteger('progress_total')->default(0)->after('progress_processed');
        });

        Schema::table('adif_import_records', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->nullable()->after('matched_contact_id');
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('adif_import_records', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
            $table->dropColumn('contact_id');
        });

        Schema::table('adif_imports', function (Blueprint $table) {
            $table->dropColumn(['progress_processed', 'progress_total']);
        });
    }
};
