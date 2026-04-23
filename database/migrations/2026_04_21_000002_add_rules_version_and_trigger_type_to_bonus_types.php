<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private const DERIVED_BONUS_CODES = [
        'sm_sec_message',
        'nts_message',
        'w1aw_bulletin',
        'elected_official_visit',
        'agency_visit',
        'media_publicity',
    ];

    private const HYBRID_BONUS_CODE = 'youth_participation';

    public function up(): void
    {
        Schema::table('bonus_types', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });

        Schema::table('bonus_types', function (Blueprint $table) {
            $table->string('rules_version', 10)->default('2025')->after('event_type_id');
            $table->string('trigger_type', 16)->default('manual')->after('code');
            $table->unique(
                ['event_type_id', 'rules_version', 'code'],
                'bonus_types_event_version_code_unique',
            );
        });

        DB::table('bonus_types')
            ->whereIn('code', self::DERIVED_BONUS_CODES)
            ->update(['trigger_type' => 'derived']);

        DB::table('bonus_types')
            ->where('code', self::HYBRID_BONUS_CODE)
            ->update(['trigger_type' => 'hybrid']);
    }

    /**
     * Destructive: removes all non-2025 bonus rows so the legacy unique(code) constraint can be restored.
     */
    public function down(): void
    {
        Schema::table('bonus_types', function (Blueprint $table) {
            $table->dropUnique('bonus_types_event_version_code_unique');
        });

        DB::table('bonus_types')->where('rules_version', '<>', '2025')->delete();

        Schema::table('bonus_types', function (Blueprint $table) {
            $table->dropColumn(['rules_version', 'trigger_type']);
        });

        Schema::table('bonus_types', function (Blueprint $table) {
            $table->unique('code');
        });
    }
};
