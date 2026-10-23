<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Contact Logging
            ['name' => 'log-contacts', 'description' => 'Log contacts, message traffic, and bulletin reception'],
            ['name' => 'edit-contacts', 'description' => 'Edit or delete existing QSOs'],

            // Event Management
            ['name' => 'view-events', 'description' => 'View events list and details'],
            ['name' => 'create-events', 'description' => 'Create new Field Day events'],
            ['name' => 'edit-events', 'description' => 'Edit existing Field Day events'],
            ['name' => 'delete-events', 'description' => 'Delete Field Day events'],
            ['name' => 'manage-bulletins', 'description' => 'Add, edit, and delete bulletin transmission times'],
            ['name' => 'verify-bonuses', 'description' => 'Approve or reject bonus point claims'],

            // Station & Equipment
            ['name' => 'view-stations', 'description' => 'View stations list and details'],
            ['name' => 'manage-stations', 'description' => 'Create, edit, and delete stations, and change their readiness'],
            ['name' => 'manage-equipment', 'description' => 'Legacy permission. Use the equipment permissions below to grant access.'],

            // Equipment Management (New)
            ['name' => 'manage-own-equipment', 'description' => 'Create, edit, and delete personal equipment in catalog'],
            ['name' => 'view-all-equipment', 'description' => 'View everyone\'s equipment catalog'],
            ['name' => 'manage-event-equipment', 'description' => 'Manage equipment assignments for events (change status, assign to stations)'],
            ['name' => 'edit-any-equipment', 'description' => 'Create equipment, and edit or delete equipment owned by any user'],

            // User Administration
            ['name' => 'manage-users', 'description' => 'Create, edit, and delete user accounts'],
            ['name' => 'manage-roles', 'description' => 'Not used. Role management requires manage-settings.'],
            ['name' => 'manage-settings', 'description' => 'Change system preferences, site branding, roles, and permissions'],

            // Shift Participation
            ['name' => 'sign-up-shifts', 'description' => 'Sign up for shifts, cancel signups, and check in and out'],

            // Content Management
            ['name' => 'manage-guestbook', 'description' => 'Manage guestbook entries (verify, edit, delete, export)'],
            ['name' => 'manage-shifts', 'description' => 'Create, edit, and manage shift schedules and assignments'],
            ['name' => 'manage-images', 'description' => 'Delete any event photo. Signed-in users can upload photos and delete their own.'],

            // Reporting
            ['name' => 'view-reports', 'description' => 'View reports and export event logs'],

            // Security
            ['name' => 'view-security-logs', 'description' => 'View security logs and activity records'],

            // Import
            ['name' => 'import-contacts', 'description' => 'Import ADIF contacts and manage external loggers'],

            // Weather
            ['name' => 'manage-weather', 'description' => 'Enter weather manually, post local alerts, and configure weather sources and units'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                ['description' => $permission['description']]
            );
        }

        $this->command->info('Created '.count($permissions).' permissions');
    }
}
