<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Descriptions for the built-in roles, frozen as of this migration.
     * Only roles without a description are filled, so admin edits survive.
     *
     * @var array<string, string>
     */
    private const array DESCRIPTIONS = [
        'System Administrator' => 'Full access to every feature and setting',
        'Event Manager' => 'Runs events: logging, stations, equipment, shifts, photos, and reports',
        'Station Captain' => 'Runs a station: logs and edits contacts, manages stations and event equipment',
        'Operator' => 'Logs contacts, signs up for shifts, and manages their own equipment',
        'Config Only' => 'Setup account for users, roles, and settings. It can\'t log contacts.',
    ];

    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (! Schema::hasColumn($table, 'description')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('description', 500)->nullable()->after('guard_name');
            });
        }

        foreach (self::DESCRIPTIONS as $name => $description) {
            DB::table($table)
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->whereNull('description')
                ->update(['description' => $description]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        if (Schema::hasColumn($table, 'description')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('description');
            });
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
