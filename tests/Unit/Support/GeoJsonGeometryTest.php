<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\GeoJsonGeometry;

/**
 * A 10x10 square spanning lon 0..10, lat 0..10. GeoJSON order is [lon, lat].
 */
function squarePolygon(): array
{
    return [
        'type' => 'Polygon',
        'coordinates' => [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]],
    ];
}

test('detects a point inside a polygon', function () {
    expect(GeoJsonGeometry::containsPoint(5.0, 5.0, squarePolygon()))->toBeTrue();
});

test('rejects a point outside a polygon', function () {
    expect(GeoJsonGeometry::containsPoint(15.0, 5.0, squarePolygon()))->toBeFalse();
    expect(GeoJsonGeometry::containsPoint(5.0, 15.0, squarePolygon()))->toBeFalse();
    expect(GeoJsonGeometry::containsPoint(-1.0, -1.0, squarePolygon()))->toBeFalse();
});

test('matches a point inside any polygon of a MultiPolygon', function () {
    $multi = [
        'type' => 'MultiPolygon',
        'coordinates' => [
            [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]],
            [[[20, 20], [30, 20], [30, 30], [20, 30], [20, 20]]],
        ],
    ];

    expect(GeoJsonGeometry::containsPoint(25.0, 25.0, $multi))->toBeTrue();
    expect(GeoJsonGeometry::containsPoint(0.5, 0.5, $multi))->toBeTrue();
    expect(GeoJsonGeometry::containsPoint(15.0, 15.0, $multi))->toBeFalse();
});

test('returns false for unsupported or missing geometry types', function () {
    expect(GeoJsonGeometry::containsPoint(5.0, 5.0, ['type' => 'Point', 'coordinates' => [5, 5]]))->toBeFalse();
    expect(GeoJsonGeometry::containsPoint(5.0, 5.0, []))->toBeFalse();
});

test('treats latitude and longitude in the correct GeoJSON order', function () {
    // Wide in longitude (0..10), narrow in latitude (0..1).
    $wide = [
        'type' => 'Polygon',
        'coordinates' => [[[0, 0], [10, 0], [10, 1], [0, 1], [0, 0]]],
    ];

    // lat 0.5, lon 9 is inside; lat 9, lon 0.5 is not.
    expect(GeoJsonGeometry::containsPoint(0.5, 9.0, $wide))->toBeTrue();
    expect(GeoJsonGeometry::containsPoint(9.0, 0.5, $wide))->toBeFalse();
});
