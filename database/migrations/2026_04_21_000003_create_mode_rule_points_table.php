<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mode_rule_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained('event_types')->cascadeOnDelete();
            $table->string('rules_version', 10);
            $table->foreignId('mode_id')->constrained('modes')->cascadeOnDelete();
            $table->integer('points');
            $table->timestamps();

            $table->unique(['event_type_id', 'rules_version', 'mode_id'], 'mode_rule_points_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mode_rule_points');
    }
};
