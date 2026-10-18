<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Seeders;

use App\Models\Band;
use Illuminate\Database\Seeder;

class BandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $bands = [
            ['name' => '2200m', 'meters' => 2200, 'frequency_mhz' => 0.1357, 'is_hf' => false, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 1],
            ['name' => '630m', 'meters' => 630, 'frequency_mhz' => 0.472, 'is_hf' => false, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 2],
            ['name' => '160m', 'meters' => 160, 'frequency_mhz' => 1.8, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 3],
            ['name' => '80m', 'meters' => 80, 'frequency_mhz' => 3.5, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 4],
            ['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 5],
            ['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 6],
            ['name' => '15m', 'meters' => 15, 'frequency_mhz' => 21.0, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 7],
            ['name' => '10m', 'meters' => 10, 'frequency_mhz' => 28.0, 'is_hf' => true, 'is_vhf_uhf' => false, 'is_satellite' => false, 'sort_order' => 8],
            ['name' => '6m', 'meters' => 6, 'frequency_mhz' => 50.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 9],
            ['name' => '2m', 'meters' => 2, 'frequency_mhz' => 144.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 10],
            ['name' => '1.25m', 'meters' => 1, 'frequency_mhz' => 222.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 11],
            ['name' => '70cm', 'meters' => null, 'frequency_mhz' => 420.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 12],
            ['name' => '33cm', 'meters' => null, 'frequency_mhz' => 902.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 13],
            ['name' => '23cm', 'meters' => null, 'frequency_mhz' => 1240.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 14],
            ['name' => '13cm', 'meters' => null, 'frequency_mhz' => 2300.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 15],
            ['name' => '9cm', 'meters' => null, 'frequency_mhz' => 3300.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 16],
            ['name' => '5cm', 'meters' => null, 'frequency_mhz' => 5650.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 17],
            ['name' => '3cm', 'meters' => null, 'frequency_mhz' => 10000.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 18],
            ['name' => '1.25cm', 'meters' => null, 'frequency_mhz' => 24000.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 19],
            ['name' => '6mm', 'meters' => null, 'frequency_mhz' => 47000.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 20],
            ['name' => '4mm', 'meters' => null, 'frequency_mhz' => 76000.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 21],
            ['name' => '2.5mm', 'meters' => null, 'frequency_mhz' => 122250.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 22],
            ['name' => '2mm', 'meters' => null, 'frequency_mhz' => 134000.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 23],
            ['name' => '1mm', 'meters' => null, 'frequency_mhz' => 241000.0, 'is_hf' => false, 'is_vhf_uhf' => true, 'is_satellite' => false, 'sort_order' => 24],
            ['name' => 'Satellite', 'meters' => null, 'frequency_mhz' => null, 'is_hf' => false, 'is_vhf_uhf' => false, 'is_satellite' => true, 'sort_order' => 25],
        ];

        foreach ($bands as $band) {
            $record = Band::firstOrCreate(['name' => $band['name']], $band);

            if ($record->sort_order !== $band['sort_order']) {
                $record->update(['sort_order' => $band['sort_order']]);
            }
        }
    }
}
