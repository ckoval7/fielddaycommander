<?php

namespace Database\Factories;

use App\Models\Station;
use App\Models\StationDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StationDevice>
 */
class StationDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'token_hash' => StationDevice::hashToken(Str::random(64)),
            'name' => null,
            'enrolled_by_user_id' => null,
            'last_seen_at' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * Use a known raw token so tests can present it as a cookie.
     */
    public function withToken(string $token): static
    {
        return $this->state([
            'token_hash' => StationDevice::hashToken($token),
        ]);
    }

    /**
     * Indicate that the browser has been revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn () => [
            'revoked_at' => now(),
        ]);
    }
}
