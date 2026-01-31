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
            // Reference data (REQUIRED - must run in this order due to foreign keys)
            EventTypeSeeder::class,
            BandSeeder::class,
            ModeSeeder::class,
            SectionSeeder::class,
            OperatingClassSeeder::class,
            BonusTypeSeeder::class,

            // Auth system (REQUIRED - must run before users)
            PermissionSeeder::class,
            RoleSeeder::class,
            SystemAdminSeeder::class,

            // Development data (OPTIONAL - comment out for production)
            UserSeeder::class,
            FieldDay2025Seeder::class,
        ]);
    }
}
