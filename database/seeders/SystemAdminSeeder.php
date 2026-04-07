<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'call_sign' => User::SYSTEM_CALL_SIGN,
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'email' => 'admin@localhost',
            'password' => Hash::make('ChangeMe123!'), // Temporary - must change in setup wizard
            'license_class' => null,
            'user_role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $admin->assignRole('Config Only');

        $this->command->info('Created system admin account (callsign: SYSTEM)');
        $this->command->warn('⚠️  Default password must be changed via setup wizard!');
    }
}
