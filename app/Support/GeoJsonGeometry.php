<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

/**
 * Point-in-polygon tests for GeoJSON geometries.
 *
 * Stateless helpers extracted from WeatherService so alert-area matching can be
 * tested and reused independently of the weather API client.
 */
class GeoJsonGeometry
{
    /**
     * Determine whether a coordinate falls inside a GeoJSON Polygon or
     * MultiPolygon. Only outer rings are considered; holes are ignored.
     *
     * @param  array{type?: string, coordinates?: array}  $geometry
     */
    public static function containsPoint(float $lat, float $lon, array $geometry): bool
    {
        $rings = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates'][0]],
            'MultiPolygon' => array_map(fn ($polygon) => $polygon[0], $geometry['coordinates']),
            default => [],
        };

        foreach ($rings as $ring) {
            if (self::ringContainsPoint($lat, $lon, $ring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ray-casting test against a single linear ring.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring
     */
    private static function ringContainsPoint(float $lat, float $lon, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        $j = $n - 1;

        for ($i = 0; $i < $n; $i++) {
            // GeoJSON coordinates are [longitude, latitude]
            $xi = $ring[$i][0]; // lon of vertex i
            $yi = $ring[$i][1]; // lat of vertex i
            $xj = $ring[$j][0]; // lon of vertex j
            $yj = $ring[$j][1]; // lat of vertex j

            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }

            $j = $i;
        }

        return $inside;
    }
}
