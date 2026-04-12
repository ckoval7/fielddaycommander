<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('exchange_class', 5)->nullable()->after('section_id');
        });

        // Backfill: extract the class token (e.g. "3A") from received_exchange.
        // Format is either "CLASS SECTION" or "CALLSIGN CLASS SECTION".
        // The class token always matches \d{1,2}[A-F].
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

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('exchange_class');
        });
    }
};
