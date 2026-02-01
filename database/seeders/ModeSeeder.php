<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ModeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modes = [
            [
                'name' => 'CW',
                'category' => 'CW',
                'points_fd' => 2,
                'points_wfd' => 2,
                'description' => 'Continuous Wave (Morse code)',
            ],
            [
                'name' => 'Phone',
                'category' => 'Phone',
                'points_fd' => 1,
                'points_wfd' => 1,
                'description' => 'Voice modes (SSB, FM, AM)',
            ],
            [
                'name' => 'Digital',
                'category' => 'Digital',
                'points_fd' => 2,
                'points_wfd' => 2,
                'description' => 'Digital modes (FT8, PSK31, RTTY, etc.)',
            ],
        ];

        foreach ($modes as $mode) {
            \App\Models\Mode::create($mode);
        }
    }
}
