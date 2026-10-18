<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns a claim's other rows may fill in on the row that is kept, when
     * the kept row has none of its own.
     *
     * @var array<int, string>
     */
    private const MERGEABLE_COLUMNS = ['notes', 'proof_file_path', 'claimed_by_user_id'];

    /**
     * Count columns where the kept row takes the largest value in the claim, so
     * collapsing racing writes never lowers a per-occurrence bonus.
     *
     * @var array<int, string>
     */
    private const MAXIMIZED_COLUMNS = ['quantity', 'calculated_points'];

    /**
     * One event_bonuses row per (event configuration, bonus type).
     *
     * Every writer treats the pair as a single claim (per-occurrence bonuses
     * carry a quantity on that one row), so extra rows only come from racing
     * exists-then-create writes. Those are collapsed first: the most
     * informative row is kept (verified, then the one carrying notes, proof
     * or a claimant, then the latest updated), fills any notes, proof or
     * claimant it lacks from the rows being removed, and takes the largest
     * quantity and points in the claim so no occurrences are lost.
     */
    public function up(): void
    {
        $duplicateGroups = DB::table('event_bonuses')
            ->select('event_configuration_id', 'bonus_type_id')
            ->groupBy('event_configuration_id', 'bonus_type_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $this->collapseGroup((int) $group->event_configuration_id, (int) $group->bonus_type_id);
        }

        Schema::table('event_bonuses', function (Blueprint $table) {
            $table->unique(['event_configuration_id', 'bonus_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_bonuses', function (Blueprint $table) {
            $table->dropUnique(['event_configuration_id', 'bonus_type_id']);
        });
    }

    private function collapseGroup(int $eventConfigurationId, int $bonusTypeId): void
    {
        $rows = DB::table('event_bonuses')
            ->where('event_configuration_id', $eventConfigurationId)
            ->where('bonus_type_id', $bonusTypeId)
            ->get()
            ->sortByDesc(fn (object $row): array => [
                (int) (bool) $row->is_verified,
                $this->informationScore($row),
                (string) $row->updated_at,
                (int) $row->id,
            ])
            ->values();

        $keep = $rows->shift();
        $fill = [];

        foreach (self::MERGEABLE_COLUMNS as $column) {
            if (! blank($keep->{$column})) {
                continue;
            }

            $donor = $rows->first(fn (object $row): bool => ! blank($row->{$column}));

            if ($donor !== null) {
                $fill[$column] = $donor->{$column};
            }
        }

        foreach (self::MAXIMIZED_COLUMNS as $column) {
            $largest = $rows->max(fn (object $row): int => (int) $row->{$column});

            if ($largest !== null && $largest > (int) $keep->{$column}) {
                $fill[$column] = $largest;
            }
        }

        if ($fill !== []) {
            DB::table('event_bonuses')->where('id', $keep->id)->update($fill);
        }

        DB::table('event_bonuses')->whereIn('id', $rows->pluck('id'))->delete();
    }

    /**
     * How much a person put into this row: notes, proof and a claimant each count.
     */
    private function informationScore(object $row): int
    {
        return collect(self::MERGEABLE_COLUMNS)
            ->filter(fn (string $column): bool => ! blank($row->{$column}))
            ->count();
    }
};
