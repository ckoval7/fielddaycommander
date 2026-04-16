<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The unique constraint on (event_configuration_id, radio_equipment_id) does not
     * account for soft-deleted rows, causing a constraint violation when attempting to
     * reuse a radio that belonged to a deleted station. Uniqueness is enforced at the
     * application layer via Rule::unique(...)->whereNull('deleted_at') instead.
     */
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropUnique('stations_event_radio_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->unique(['event_configuration_id', 'radio_equipment_id'], 'stations_event_radio_unique');
        });
    }
};
