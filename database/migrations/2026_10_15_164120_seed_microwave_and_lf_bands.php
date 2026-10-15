<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the LF (2200m, 630m) and microwave (13cm through 1mm) bands.
 *
 * Winter Field Day 2027 rewards three contacts on twelve different bands, which
 * the twelve bands from 160m to 23cm cannot satisfy once any band is skipped.
 * 12m, 17m, 30m and 60m stay absent: neither WFD nor ARRL Field Day allows them.
 *
 * Skipped on an empty table, where BandSeeder supplies the full list. Existing rows are only re-ordered, never edited otherwise, so admin changes to
 * the allowed_* flags survive. LF bands sort before 160m, microwave after 23cm,
 * and Satellite stays last.
 */
return new class extends Migration
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function bands(): array
    {
        return [
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
    }

    /**
     * Names of the bands added by this migration.
     *
     * @return array<int, string>
     */
    private function addedBandNames(): array
    {
        return ['2200m', '630m', '13cm', '9cm', '5cm', '3cm', '1.25cm', '6mm', '4mm', '2.5mm', '2mm', '1mm'];
    }

    public function up(): void
    {
        // Fresh installs get every band from BandSeeder; only existing installs need this.
        if (! DB::table('bands')->exists()) {
            return;
        }

        $now = now();

        foreach ($this->bands() as $band) {
            $exists = DB::table('bands')->where('name', $band['name'])->exists();

            if ($exists) {
                DB::table('bands')->where('name', $band['name'])->update([
                    'sort_order' => $band['sort_order'],
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('bands')->insert($band + [
                'allowed_fd' => true,
                'allowed_wfd' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $added = DB::table('bands')->whereIn('name', $this->addedBandNames())->pluck('id');

        $inUse = DB::table('contacts')->whereIn('band_id', $added)->exists();

        if ($inUse) {
            return;
        }

        DB::table('bands')->whereIn('id', $added)->delete();

        $order = ['160m', '80m', '40m', '20m', '15m', '10m', '6m', '2m', '1.25m', '70cm', '33cm', '23cm', 'Satellite'];

        foreach ($order as $index => $name) {
            DB::table('bands')->where('name', $name)->update(['sort_order' => $index + 1]);
        }
    }
};
