<?php

namespace Database\Factories;

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdifImportRecord>
 */
class AdifImportRecordFactory extends Factory
{
    public function definition(): array
    {
        $callsign = strtoupper(fake()->bothify('??#???'));

        return [
            'adif_import_id' => AdifImport::factory(),
            'raw_data' => [
                'CALL' => $callsign,
                'BAND' => '20M',
                'MODE' => 'SSB',
                'QSO_DATE' => '20260410',
                'TIME_ON' => '120000',
            ],
            'callsign' => $callsign,
            'qso_time' => now(),
            'band_name' => '20M',
            'mode_name' => 'SSB',
            'section_code' => 'CT',
            'exchange_class' => '3A',
            'station_identifier' => 'K3CPK',
            'operator_callsign' => 'K3CPK',
            'status' => AdifRecordStatus::Pending,
        ];
    }

    public function mapped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdifRecordStatus::Mapped,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdifRecordStatus::Ready,
        ]);
    }
}
