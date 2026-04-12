<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On fresh installs, exchange_class already exists in the create migration.
        if (Schema::hasColumn('contacts', 'exchange_class')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('exchange_class', 5)->nullable()->after('section_id');
        });

        // Backfill from the deprecated received_exchange column if it exists.
        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('contacts', 'received_exchange')) {
            DB::statement("
                UPDATE contacts
                SET exchange_class = CASE
                    WHEN received_exchange REGEXP '^[0-9]{1,2}[A-Fa-f] '
                    THEN UPPER(SUBSTRING_INDEX(received_exchange, ' ', 1))
                    WHEN received_exchange REGEXP ' [0-9]{1,2}[A-Fa-f]( |$)'
                    THEN UPPER(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(received_exchange, ' ', 2), ' ', -1)))
                    ELSE NULL
                END
                WHERE received_exchange IS NOT NULL AND exchange_class IS NULL
            ");
        }
    }

    public function down(): void
    {
        // Only drop if the create migration still has received_exchange (existing installs).
        if (Schema::hasColumn('contacts', 'exchange_class') && Schema::hasColumn('contacts', 'received_exchange')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->dropColumn('exchange_class');
            });
        }
    }
};
