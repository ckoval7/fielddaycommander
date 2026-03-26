<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Reference data (must run in this order due to foreign keys)
            EventTypeSeeder::class,
            BandSeeder::class,
            ModeSeeder::class,
            SectionSeeder::class,
            OperatingClassSeeder::class,
            BonusTypeSeeder::class,

            // Auth system (must run before users)
            PermissionSeeder::class,
            RoleSeeder::class,
            SystemAdminSeeder::class,
        ]);
    }
}
