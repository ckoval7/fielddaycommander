<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Add the Mexico "MX" section used by Winter Field Day 2027 exchanges.
 *
 * Region is DX, matching the DX (International) row: the sections.region enum
 * has no MX value and the dashboard groups non-US/CA sections under DX.
 */
return new class extends Migration
{
    private const CODE = 'MX';

    public function up(): void
    {
        if (DB::table('sections')->where('code', self::CODE)->exists()) {
            return;
        }

        DB::table('sections')->insert([
            'code' => self::CODE,
            'name' => 'Mexico',
            'region' => 'DX',
            'country' => 'MX',
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
