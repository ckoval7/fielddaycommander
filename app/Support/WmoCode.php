<?php

namespace App\Support;

class WmoCode
{
    private function __construct() {}

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

    public static function color(int $code): string
    {
        return match (true) {
            $code <= 1 => 'text-amber-400 dark:text-amber-300',   // Clear, mainly clear
            $code === 2 => 'text-slate-400 dark:text-slate-300',    // Partly cloudy
            $code === 3 => 'text-slate-500 dark:text-slate-400',    // Overcast
            $code === 45 || $code === 48 => 'text-gray-400 dark:text-gray-300',    // Fog
            $code >= 51 && $code <= 67 => 'text-blue-400 dark:text-blue-300',    // Drizzle / rain
            $code >= 71 && $code <= 77 => 'text-sky-200 dark:text-sky-100',      // Snow
            $code >= 80 && $code <= 82 => 'text-blue-500 dark:text-blue-400',    // Showers
            $code >= 85 && $code <= 86 => 'text-sky-300 dark:text-sky-200',      // Snow showers
            $code >= 95 => 'text-yellow-400 dark:text-yellow-300',// Thunderstorm
            default => 'text-slate-400 dark:text-slate-300',
        };
    }
}
