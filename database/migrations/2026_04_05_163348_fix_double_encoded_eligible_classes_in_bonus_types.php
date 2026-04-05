<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix double-encoded JSON in eligible_classes column.
     * Data was stored as '"[\"A\",\"B\"]"' instead of '["A","B"]'.
     */
    public function up(): void
    {
        $rows = DB::table('bonus_types')
            ->whereNotNull('eligible_classes')
            ->get(['id', 'eligible_classes']);

        foreach ($rows as $row) {
            $decoded = json_decode($row->eligible_classes, true);

            // If decoding yields a string, it's double-encoded — decode again
            if (is_string($decoded)) {
                $fixed = json_decode($decoded, true);
                if (is_array($fixed)) {
                    DB::table('bonus_types')
                        ->where('id', $row->id)
                        ->update(['eligible_classes' => json_encode($fixed)]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible — the double-encoding was a bug
    }
};
