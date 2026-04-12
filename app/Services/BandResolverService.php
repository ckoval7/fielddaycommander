<?php

namespace App\Services;

use App\Models\Band;

class BandResolverService
{
    /**
     * Band frequency ranges in MHz [low, high] for frequency-to-band resolution.
     *
     * @var array<string, array{float, float}>
     */
    private const BAND_RANGES_MHZ = [
        '160m' => [1.8, 2.0],
        '80m' => [3.5, 4.0],
        '40m' => [7.0, 7.3],
        '20m' => [14.0, 14.35],
        '15m' => [21.0, 21.45],
        '10m' => [28.0, 29.7],
        '6m' => [50.0, 54.0],
        '2m' => [144.0, 148.0],
        '1.25m' => [222.0, 225.0],
        '70cm' => [420.0, 450.0],
        '33cm' => [902.0, 928.0],
        '23cm' => [1240.0, 1300.0],
    ];

    /**
     * Resolve a band name string to a Band ID.
     */
    public function resolveByName(?string $bandName): ?int
    {
        if ($bandName === null) {
            return null;
        }

        $bands = Band::query()->pluck('id', 'name')->mapWithKeys(
            fn ($id, $name) => [strtolower($name) => $id]
        )->toArray();

        return $bands[strtolower($bandName)] ?? null;
    }

    /**
     * Resolve a frequency in Hz to a Band ID.
     */
    public function resolveByFrequencyHz(?int $frequencyHz): ?int
    {
        if ($frequencyHz === null) {
            return null;
        }

        $frequencyMhz = $frequencyHz / 1_000_000;

        foreach (self::BAND_RANGES_MHZ as $bandName => [$low, $high]) {
            if ($frequencyMhz >= $low && $frequencyMhz <= $high) {
                return $this->resolveByName($bandName);
            }
        }

        return null;
    }
}
