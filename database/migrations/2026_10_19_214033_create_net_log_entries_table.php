<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('net_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('logged_at');
            $table->string('from_station', 50);
            $table->string('to_station', 50)->nullable();
            $table->string('from_callsign', 20)->nullable();
            $table->string('to_callsign', 20)->nullable();
            $table->text('message');
            $table->string('priority', 20)->default('routine');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_configuration_id', 'logged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('net_log_entries');
    }
};
