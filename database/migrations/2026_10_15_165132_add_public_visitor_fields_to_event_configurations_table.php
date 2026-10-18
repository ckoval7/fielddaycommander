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
        Schema::table('event_configurations', function (Blueprint $table) {
            $table->boolean('is_open_to_public')->default(false);
            $table->text('public_directions')->nullable();
            $table->text('public_visiting_hours')->nullable();
            $table->string('public_contact_email', 255)->nullable();
            $table->string('public_contact_phone', 50)->nullable();
            $table->string('public_website_url', 255)->nullable();
            $table->text('public_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'is_open_to_public',
                'public_directions',
                'public_visiting_hours',
                'public_contact_email',
                'public_contact_phone',
                'public_website_url',
                'public_description',
            ]);
        });
    }
};
