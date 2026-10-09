<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemAdminSeeder extends Seeder
{
    /**
     * Create the SYSTEM admin account if it does not already exist.
     *
     * An existing account is left untouched so a re-run never resets the
     * password chosen in the setup wizard.
     */
    public function run(): void
    {
        $admin = User::withTrashed()->firstOrCreate(
            ['call_sign' => User::SYSTEM_CALL_SIGN],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'admin@localhost',
                'password' => Hash::make('ChangeMe123!'), // Temporary - must change in setup wizard
                'license_class' => null,
                'user_role' => 'admin',
                'email_verified_at' => now(),
            ],
        );

        $admin->assignRole('Config Only');

        if (! $admin->wasRecentlyCreated) {
            $this->command->info('System admin account already exists (callsign: SYSTEM), skipping');

            return;
        }

        $this->command->info('Created system admin account (callsign: SYSTEM)');
        $this->command->warn('⚠️  Default password must be changed via setup wizard!');
    }
}
