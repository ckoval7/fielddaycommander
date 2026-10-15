<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Add the Yukon "YT" RAC section recognised by Winter Field Day.
 *
 * ARRL still counts Yukon under Territories (TER), so the section map folds
 * YT into TER for non-WFD events.
 */
return new class extends Migration
{
    private const CODE = 'YT';

    public function up(): void
    {
        if (DB::table('sections')->where('code', self::CODE)->exists()) {
            return;
        }

        DB::table('sections')->insert([
            'code' => self::CODE,
            'name' => 'Yukon',
            'region' => 'VE',
            'country' => 'CA',
            'is_active' => true,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function down(): void
    {
        DB::table('sections')->where('code', self::CODE)->delete();
    }
};
