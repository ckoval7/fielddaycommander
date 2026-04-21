<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('rules_version', 10)->nullable()->after('year')->index();
        });

        // Backfill existing rows so 2025 scores cannot later shift.
        DB::table('events')->whereNull('rules_version')->update([
            'rules_version' => DB::raw('CAST(`year` AS CHAR)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['rules_version']);
            $table->dropColumn('rules_version');
        });
    }
};
