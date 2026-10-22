<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roll calls (PAR) on a net, and the stations each one expects to answer.
     */
    public function up(): void
    {
        Schema::create('roll_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('net_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['net_id', 'ended_at']);
        });

        Schema::create('roll_call_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roll_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 50);
            $table->string('callsign', 20)->nullable();
            $table->dateTime('accounted_at')->nullable();
            $table->foreignId('net_log_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roll_call_stations');
        Schema::dropIfExists('roll_calls');
    }
};
