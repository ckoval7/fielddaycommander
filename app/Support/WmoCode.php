<?php

namespace App\Support;

class WmoCode
{
    /** @var array<int, string> */
    private static array $icons = [
        0 => 'o-sun',
        1 => 'o-sun',
        2 => 'o-cloud',
        3 => 'o-cloud',
        45 => 'o-cloud',
        48 => 'o-cloud',
        51 => 'o-cloud-arrow-down',
        53 => 'o-cloud-arrow-down',
        55 => 'o-cloud-arrow-down',
        61 => 'o-cloud-arrow-down',
        63 => 'o-cloud-arrow-down',
        65 => 'o-cloud-arrow-down',
        71 => 'o-cloud',
        73 => 'o-cloud',
        75 => 'o-cloud',
        77 => 'o-cloud',
        80 => 'o-cloud-arrow-down',
        81 => 'o-cloud-arrow-down',
        82 => 'o-cloud-arrow-down',
        85 => 'o-cloud',
        86 => 'o-cloud',
        95 => 'o-bolt',
        96 => 'o-bolt',
        99 => 'o-bolt',
    ];

    /** @var array<int, string> */
    private static array $labels = [
        0 => 'Clear',
        1 => 'Mainly Clear',
        2 => 'Partly Cloudy',
        3 => 'Overcast',
        45 => 'Fog',
        48 => 'Freezing Fog',
        51 => 'Light Drizzle',
        53 => 'Moderate Drizzle',
        55 => 'Heavy Drizzle',
        61 => 'Light Rain',
        63 => 'Moderate Rain',
        65 => 'Heavy Rain',
        71 => 'Light Snow',
        73 => 'Moderate Snow',
        75 => 'Heavy Snow',
        77 => 'Snow Grains',
        80 => 'Light Showers',
        81 => 'Moderate Showers',
        82 => 'Heavy Showers',
        85 => 'Light Snow Showers',
        86 => 'Heavy Snow Showers',
        95 => 'Thunderstorm',
        96 => 'Thunderstorm with Hail',
        99 => 'Thunderstorm with Heavy Hail',
    ];

    public static function icon(int $code): string
    {
        return self::$icons[$code] ?? 'o-cloud';
    }

    public static function label(int $code): string
    {
        return self::$labels[$code] ?? 'Unknown';
    }
}
