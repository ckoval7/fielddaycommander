<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Earn the WFD bulletin objectives from the saved bulletin copy.
 *
 * Saving the bulletin now claims the objective the same way it awards the
 * ARRL W1AW bonus, so the rows move from manual to derived. Existing claims
 * are untouched.
 */
return new class extends Migration
{
    /** @var array<string, string> Rules version => objective code. */
    private const BULLETIN_CODES = [
        '2026' => 'special_bulletin',
        '2027' => 'bulletin',
    ];

    public function up(): void
    {
        $this->setTriggerType('derived');
    }

    public function down(): void
    {
        $this->setTriggerType('manual');
    }

    private function setTriggerType(string $triggerType): void
    {
        $eventTypeId = DB::table('event_types')->where('code', 'WFD')->value('id');

        if (! $eventTypeId) {
            return;
        }

        foreach (self::BULLETIN_CODES as $version => $code) {
            DB::table('bonus_types')
                ->where('event_type_id', $eventTypeId)
                ->where('rules_version', $version)
                ->where('code', $code)
                ->update(['trigger_type' => $triggerType]);
        }
    }
};
