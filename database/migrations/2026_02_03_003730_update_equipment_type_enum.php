<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support ENUM modification, but also doesn't enforce ENUM values
        // So we only need to modify for MySQL/MariaDB
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE equipment MODIFY COLUMN type ENUM(
                'radio',
                'antenna',
                'amplifier',
                'computer',
                'power_supply',
                'accessory',
                'tool',
                'furniture',
                'other'
            ) NOT NULL");
        } else {
            // For SQLite in testing, recreate the table with updated enum values
            Schema::table('equipment', function (Blueprint $table) {
                $table->string('type_temp')->nullable();
            });

            DB::statement('UPDATE equipment SET type_temp = type');

            // Drop the index before dropping the column
            Schema::table('equipment', function (Blueprint $table) {
                $table->dropIndex('equipment_type_index');
            });

            Schema::table('equipment', function (Blueprint $table) {
                $table->dropColumn('type');
            });

            Schema::table('equipment', function (Blueprint $table) {
                $table->enum('type', [
                    'radio',
                    'antenna',
                    'amplifier',
                    'computer',
                    'power_supply',
                    'accessory',
                    'tool',
                    'furniture',
                    'other',
                ])->after('model');
            });

            DB::statement('UPDATE equipment SET type = type_temp');

            Schema::table('equipment', function (Blueprint $table) {
                $table->dropColumn('type_temp');
            });

            // Recreate the index
            Schema::table('equipment', function (Blueprint $table) {
                $table->index('type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE equipment MODIFY COLUMN type ENUM(
                'radio',
                'antenna',
                'amplifier',
                'tuner',
                'power_supply',
                'computer',
                'other'
            ) NOT NULL");
        } else {
            // For SQLite, recreate with original enum values
            Schema::table('equipment', function (Blueprint $table) {
                $table->string('type_temp')->nullable();
            });

            DB::statement('UPDATE equipment SET type_temp = type');

            // Drop the index before dropping the column
            Schema::table('equipment', function (Blueprint $table) {
                $table->dropIndex('equipment_type_index');
            });

            Schema::table('equipment', function (Blueprint $table) {
                $table->dropColumn('type');
            });

            Schema::table('equipment', function (Blueprint $table) {
                $table->enum('type', [
                    'radio',
                    'antenna',
                    'amplifier',
                    'tuner',
                    'power_supply',
                    'computer',
                    'other',
                ])->after('model');
            });

            DB::statement('UPDATE equipment SET type = type_temp');

            Schema::table('equipment', function (Blueprint $table) {
                $table->dropColumn('type_temp');
            });

            // Recreate the index
            Schema::table('equipment', function (Blueprint $table) {
                $table->index('type');
            });
        }
    }
};
