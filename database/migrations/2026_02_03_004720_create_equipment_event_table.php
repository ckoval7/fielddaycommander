<?php

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
        Schema::create('equipment_event', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->foreignId('equipment_id')
                ->constrained('equipment')
                ->cascadeOnDelete();

            $table->foreignId('event_id')
                ->constrained('events')
                ->cascadeOnDelete();

            $table->foreignId('station_id')
                ->nullable()
                ->constrained('stations')
                ->nullOnDelete();

            $table->foreignId('assigned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Status tracking
            $table->enum('status', [
                'committed',
                'delivered',
                'in_use',
                'returned',
                'cancelled',
                'lost',
                'damaged',
            ])->default('committed');

            // Timestamps for status workflow
            $table->timestamp('committed_at')->useCurrent();
            $table->timestamp('expected_delivery_at')->nullable();

            // Notes
            $table->text('delivery_notes')->nullable();
            $table->text('manager_notes')->nullable();

            // Status change tracking
            $table->timestamp('status_changed_at')->useCurrent();
            $table->foreignId('status_changed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Indexes
            $table->index(['equipment_id', 'event_id']);
            $table->index('status');
            $table->index('station_id');
            $table->index('expected_delivery_at');

            // Unique constraint: equipment can only be committed once per event
            $table->unique(['equipment_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment_event');
    }
};
