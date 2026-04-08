<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['call_sign' => User::SYSTEM_CALL_SIGN],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'admin@localhost',
                'password' => Hash::make('ChangeMe123!'),
                'license_class' => null,
                'user_role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        if (! $admin->hasRole('Config Only')) {
            $admin->assignRole('Config Only');
        }

        if ($admin->wasRecentlyCreated) {
            $this->command->info('Created system admin account (callsign: SYSTEM)');
            $this->command->warn('⚠️  Default password must be changed via setup wizard!');
        } else {
            $this->command->info('System admin account already exists — skipped');
        }
    }
}
