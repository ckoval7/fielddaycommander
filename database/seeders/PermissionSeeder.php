<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Contact Logging
            ['name' => 'log-contacts', 'description' => 'Create new QSO entries'],
            ['name' => 'edit-contacts', 'description' => 'Edit or delete existing QSOs'],

            // Event Management
            ['name' => 'view-events', 'description' => 'View events list and details'],
            ['name' => 'create-events', 'description' => 'Create new Field Day events'],
            ['name' => 'edit-events', 'description' => 'Edit existing Field Day events'],
            ['name' => 'delete-events', 'description' => 'Delete Field Day events'],
            ['name' => 'manage-bulletins', 'description' => 'Manage W1AW bulletin schedule'],
            ['name' => 'verify-bonuses', 'description' => 'Approve or reject bonus point claims'],

            // Station & Equipment
            ['name' => 'view-stations', 'description' => 'View stations list and details'],
            ['name' => 'manage-stations', 'description' => 'Add and edit operating stations'],
            ['name' => 'manage-equipment', 'description' => 'Manage equipment inventory'],

            // Equipment Management (New)
            ['name' => 'manage-own-equipment', 'description' => 'Create, edit, and delete personal equipment in catalog'],
            ['name' => 'view-all-equipment', 'description' => 'View everyone\'s equipment catalog'],
            ['name' => 'manage-event-equipment', 'description' => 'Manage equipment assignments for events (change status, assign to stations)'],
            ['name' => 'edit-any-equipment', 'description' => 'Edit equipment details for any user'],

            // User Administration
            ['name' => 'manage-users', 'description' => 'Create, edit, and delete user accounts'],
            ['name' => 'manage-roles', 'description' => 'Create roles and assign permissions'],
            ['name' => 'manage-settings', 'description' => 'Configure system settings and preferences'],

            // Shift Participation
            ['name' => 'sign-up-shifts', 'description' => 'Sign up for, check in/out of shifts'],

            // Content Management
            ['name' => 'manage-guestbook', 'description' => 'Manage guestbook entries (verify, edit, delete, export)'],
            ['name' => 'manage-shifts', 'description' => 'Create, edit, and manage shift schedules and assignments'],
            ['name' => 'manage-images', 'description' => 'Upload and delete event photos'],

            // Reporting
            ['name' => 'view-reports', 'description' => 'Access detailed score reports'],

            // Security
            ['name' => 'view-security-logs', 'description' => 'View security logs and activity records'],

            // Import
            ['name' => 'import-contacts', 'description' => 'Import contacts from ADIF files'],

            // Weather
            ['name' => 'manage-weather', 'description' => 'Manually enter weather data and trigger storm alerts'],
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
