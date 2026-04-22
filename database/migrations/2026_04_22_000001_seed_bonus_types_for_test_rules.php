<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds bonus_types rows for the synthetic FD rules_version='TEST' ruleset.
     *
     * Copies every FD 2025 row forward unchanged, then adds a "Use Field Day
     * Commander" bonus worth a flat 100 points. Used to demo the admin rescore
     * flow against a ruleset that actually differs from 2025.
     */
    public function up(): void
    {
        $fdEventTypeId = DB::table('event_types')->where('code', 'FD')->value('id');

        if (! $fdEventTypeId) {
            return;
        }

        $rows = DB::table('bonus_types')
            ->where('event_type_id', $fdEventTypeId)
            ->where('rules_version', '2025')
            ->get();

        foreach ($rows as $row) {
            $new = (array) $row;
            unset($new['id'], $new['created_at'], $new['updated_at']);
            $new['rules_version'] = 'TEST';

            DB::table('bonus_types')->updateOrInsert(
                [
                    'event_type_id' => $fdEventTypeId,
                    'rules_version' => 'TEST',
                    'code' => $new['code'],
                ],
                [
                    ...$new,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('bonus_types')->updateOrInsert(
            [
                'event_type_id' => $fdEventTypeId,
                'rules_version' => 'TEST',
                'code' => 'use_fd_commander',
            ],
            [
                'event_type_id' => $fdEventTypeId,
                'rules_version' => 'TEST',
                'code' => 'use_fd_commander',
                'name' => 'Use Field Day Commander',
                'description' => 'Test bonus: awarded for logging the event in Field Day Commander.',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('bonus_types')->where('rules_version', 'TEST')->delete();
    }
};
