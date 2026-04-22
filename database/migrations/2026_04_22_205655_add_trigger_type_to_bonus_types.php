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
            $table->string('trigger_type', 16)->default('manual')->after('code');
        });

        DB::table('bonus_types')
            ->whereIn('code', [
                'sm_sec_message',
                'nts_message',
                'w1aw_bulletin',
                'elected_official_visit',
                'agency_visit',
                'media_publicity',
            ])
            ->update(['trigger_type' => 'derived']);

        DB::table('bonus_types')
            ->where('code', 'youth_participation')
            ->update(['trigger_type' => 'hybrid']);
    }

    public function down(): void
    {
        Schema::table('bonus_types', function (Blueprint $table) {
            $table->dropColumn('trigger_type');
        });
    }
};
