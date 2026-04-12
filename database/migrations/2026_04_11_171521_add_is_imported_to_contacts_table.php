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
        // On fresh installs, this column already exists in the create migration.
        if (Schema::hasColumn('contacts', 'is_imported')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_imported')->default(false)->after('is_transcribed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('is_imported');
        });
    }
};
