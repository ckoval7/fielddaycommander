<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
        '2200m' => [0.1357, 0.1378],
        '630m' => [0.472, 0.479],
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
        '13cm' => [2300.0, 2450.0],
        '9cm' => [3300.0, 3500.0],
        '5cm' => [5650.0, 5925.0],
        '3cm' => [10000.0, 10500.0],
        '1.25cm' => [24000.0, 24250.0],
        '6mm' => [47000.0, 47200.0],
        '4mm' => [75500.0, 81000.0],
        '2.5mm' => [122250.0, 123000.0],
        '2mm' => [134000.0, 141000.0],
        '1mm' => [241000.0, 250000.0],
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

    /**
     * Whether a frequency in Hz falls inside a band. Bands with no known
     * range (Satellite) accept any frequency.
     */
    public function bandContainsFrequencyHz(Band $band, int $frequencyHz): bool
    {
        $range = self::BAND_RANGES_MHZ[strtolower($band->name)] ?? null;

        if ($range === null) {
            return true;
        }

        $frequencyMhz = $frequencyHz / 1_000_000;

        return $frequencyMhz >= $range[0] && $frequencyMhz <= $range[1];
    }

    /**
     * Frequency ranges keyed by band id, for checking a dial frequency in
     * the browser before a contact is queued.
     *
     * @param  iterable<Band>  $bands
     * @return array<int, array{name: string, low: float, high: float}>
     */
    public function rangesFor(iterable $bands): array
    {
        $ranges = [];

        foreach ($bands as $band) {
            $range = self::BAND_RANGES_MHZ[strtolower($band->name)] ?? null;

            if ($range !== null) {
                $ranges[$band->id] = ['name' => $band->name, 'low' => $range[0], 'high' => $range[1]];
            }
        }

        return $ranges;
    }
}
