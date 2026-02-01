<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'call_sign' => 'ADMIN',
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
                'license_class' => 'Extra',
                'user_role' => 'admin',
            ],
            [
                'call_sign' => 'W1AW',
                'first_name' => 'Hiram',
                'last_name' => 'Maxim',
                'email' => 'w1aw@example.com',
                'password' => Hash::make('password'),
                'license_class' => 'Extra',
                'user_role' => 'user',
            ],
            [
                'call_sign' => 'K3UHF',
                'first_name' => 'Test',
                'last_name' => 'Operator',
                'email' => 'test@example.com',
                'password' => Hash::make('password'),
                'license_class' => 'General',
                'user_role' => 'user',
            ],
        ];

        foreach ($users as $user) {
            \App\Models\User::create($user);
        }
    }
}
