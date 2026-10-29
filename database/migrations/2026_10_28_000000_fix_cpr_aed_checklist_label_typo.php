<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix "versed else trained" in the CPR/AED safety checklist item that was
 * already copied into existing events.
 */
return new class extends Migration
{
    private const OLD_LABEL = 'First Aid - CPR - AED versed else trained participant/s on site for full Field Day period';

    private const NEW_LABEL = 'First Aid - CPR - AED versed or trained participant/s on site for full Field Day period';

    public function up(): void
    {
        DB::table('safety_checklist_items')
            ->where('label', self::OLD_LABEL)
            ->update(['label' => self::NEW_LABEL]);
    }

    public function down(): void
    {
        DB::table('safety_checklist_items')
            ->where('label', self::NEW_LABEL)
            ->update(['label' => self::OLD_LABEL]);
    }
};
