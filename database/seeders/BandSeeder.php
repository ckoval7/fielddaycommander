<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class BandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $bands = [
            ['name' => '160m', 'meters' => 160, 'frequency_mhz' => 1.8, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 1],
            ['name' => '80m', 'meters' => 80, 'frequency_mhz' => 3.5, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 2],
            ['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 3],
            ['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 4],
            ['name' => '15m', 'meters' => 15, 'frequency_mhz' => 21.0, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 5],
            ['name' => '10m', 'meters' => 10, 'frequency_mhz' => 28.0, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 6],
            ['name' => '6m', 'meters' => 6, 'frequency_mhz' => 50.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 7],
            ['name' => '2m', 'meters' => 2, 'frequency_mhz' => 144.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 8],
            ['name' => '1.25m', 'meters' => 1, 'frequency_mhz' => 222.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 9],
            ['name' => '70cm', 'meters' => null, 'frequency_mhz' => 420.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 10],
            ['name' => '33cm', 'meters' => null, 'frequency_mhz' => 902.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 11],
            ['name' => '23cm', 'meters' => null, 'frequency_mhz' => 1240.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 12],
            ['name' => 'Satellite', 'meters' => null, 'frequency_mhz' => null, 'is_hf' => false, 'is_vhf_uhf' => false, 'is_satellite' => true, 'sort_order' => 13],
        ];

        foreach ($bands as $band) {
            \App\Models\Band::create($band);
        }
    }
}
