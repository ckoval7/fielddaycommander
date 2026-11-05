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
        Schema::create('relays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('enrollment_code_hash', 64)->nullable()->unique();
            $table->timestamp('enrollment_expires_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('relay_version', 32)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relays');
    }
};
