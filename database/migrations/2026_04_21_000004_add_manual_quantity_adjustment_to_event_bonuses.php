<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_bonuses', function (Blueprint $table) {
            $table->integer('manual_quantity_adjustment')->nullable()->after('quantity');
        });

        // Move numeric notes values for youth_participation into the new column.
        $youthBonusIds = DB::table('bonus_types')
            ->where('code', 'youth_participation')
            ->pluck('id');

        if ($youthBonusIds->isEmpty()) {
            return;
        }

        DB::table('event_bonuses')
            ->whereIn('bonus_type_id', $youthBonusIds)
            ->whereNotNull('notes')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    if (! ctype_digit((string) $row->notes)) {
                        continue;
                    }
                    DB::table('event_bonuses')
                        ->where('id', $row->id)
                        ->update([
                            'manual_quantity_adjustment' => (int) $row->notes,
                            'notes' => null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('event_bonuses', function (Blueprint $table) {
            $table->dropColumn('manual_quantity_adjustment');
        });
    }
};
