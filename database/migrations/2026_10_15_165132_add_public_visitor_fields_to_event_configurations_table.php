<?php

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
            $table->string('public_contact', 255)->nullable();
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
                'public_contact',
                'public_description',
            ]);
        });
    }
};
