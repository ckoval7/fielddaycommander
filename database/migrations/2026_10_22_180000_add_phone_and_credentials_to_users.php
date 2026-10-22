<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A contact phone on each user, and the emergency communications
     * credentials they hold, for the ICS-211 and ICS-205A.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
        });

        Schema::create('user_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('credential', 20);
            $table->string('level', 20)->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'credential']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_credentials');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
