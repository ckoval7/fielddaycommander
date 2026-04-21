<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonus_types', function (Blueprint $table) {
            // Drop the existing global-unique on code; it becomes unique per version.
            $table->dropUnique(['code']);
        });

        Schema::table('bonus_types', function (Blueprint $table) {
            $table->string('rules_version', 10)->nullable()->after('event_type_id');
        });

        DB::table('bonus_types')->whereNull('rules_version')->update([
            'rules_version' => '2025',
        ]);

        Schema::table('bonus_types', function (Blueprint $table) {
            $table->string('rules_version', 10)->default('2025')->change();
            $table->unique(['event_type_id', 'rules_version', 'code'], 'bonus_types_scope_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bonus_types', function (Blueprint $table) {
            $table->dropUnique('bonus_types_scope_code_unique');
            $table->dropColumn('rules_version');
            $table->unique('code');
        });
    }
};
