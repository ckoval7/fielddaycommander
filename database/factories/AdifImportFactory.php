<?php

namespace Database\Factories;

use App\Enums\AdifImportStatus;
use App\Models\AdifImport;
use App\Models\EventConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdifImport>
 */
class AdifImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'event_configuration_id' => EventConfiguration::factory(),
            'user_id' => User::factory(),
            'filename' => fake()->word().'.adi',
            'status' => AdifImportStatus::PendingMapping,
            'total_records' => 0,
            'mapped_records' => 0,
            'imported_records' => 0,
            'skipped_records' => 0,
            'merged_records' => 0,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdifImportStatus::Completed,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AdifImportStatus::PendingReview,
        ]);
    }
}
