<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_configurations', function (Blueprint $table) {
            $table->boolean('interest_form_enabled')->default(false)->after('guestbook_enabled');
        });

        Schema::create('event_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('callsign', 20)->nullable();
            $table->string('email', 255);
            $table->string('license_class', 20)->nullable();
            $table->json('availability');
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['event_configuration_id', 'email']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_interests');

        Schema::table('event_configurations', function (Blueprint $table) {
            $table->dropColumn('interest_form_enabled');
        });
    }
};
