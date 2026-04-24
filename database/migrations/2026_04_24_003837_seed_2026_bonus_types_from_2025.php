<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-fill bonus_types rows for rules_version='2026' by cloning the '2025'
 * rows for the same event_type.
 *
 * The FieldDay2026 ruleset inherits 2025 verbatim (no ARRL-published changes
 * as of the 2026 season), but bonus lookups partition by rules_version and
 * would otherwise return nothing for 2026 events — leaving bonus UI blank.
 *
 * Idempotent: only inserts rows that do not already exist.
 */
return new class extends Migration
{
    private const CHILD_VERSION = '2026';

    private const PARENT_VERSION = '2025';

    public function up(): void
    {
        $parents = DB::table('bonus_types')
            ->where('rules_version', self::PARENT_VERSION)
            ->get();

        if ($parents->isEmpty()) {
            return;
        }

        $existingKeys = DB::table('bonus_types')
            ->where('rules_version', self::CHILD_VERSION)
            ->get(['event_type_id', 'code'])
            ->map(fn ($row) => $row->event_type_id.'|'.$row->code)
            ->all();

        $existing = array_flip($existingKeys);

        $now = now();
        $rows = [];

        foreach ($parents as $parent) {
            $key = $parent->event_type_id.'|'.$parent->code;

            if (isset($existing[$key])) {
                continue;
            }

            $row = (array) $parent;
            unset($row['id']);
            $row['rules_version'] = self::CHILD_VERSION;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            $rows[] = $row;
        }

        if ($rows !== []) {
            DB::table('bonus_types')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::table('bonus_types')
            ->where('rules_version', self::CHILD_VERSION)
            ->delete();
    }
};
