<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklists as records: a checklist has items, and each run is one pass
 * through it for one subject (the event, a station or a person).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope')->default('event');
            $table->string('completion_rule')->default('required_items');
            $table->string('bonus_code')->nullable();
            $table->string('template_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_configuration_id', 'sort_order']);
        });

        Schema::create('checklist_shift_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();
            $table->foreignId('shift_role_id')->constrained('shift_roles')->cascadeOnDelete();

            $table->unique(['checklist_id', 'shift_role_id']);
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();
            $table->string('label', 500);
            $table->text('help_text')->nullable();
            $table->string('answer_type')->default('checkbox');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['checklist_id', 'sort_order']);
        });

        Schema::create('checklist_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();
            $table->string('subject_key');
            $table->foreignId('station_id')->nullable()->constrained('stations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['checklist_id', 'subject_key']);
        });

        Schema::create('checklist_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_run_id')->constrained('checklist_runs')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('checklist_items')->cascadeOnDelete();
            $table->string('value', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['checklist_run_id', 'checklist_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_answers');
        Schema::dropIfExists('checklist_runs');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklist_shift_role');
        Schema::dropIfExists('checklists');
    }
};
