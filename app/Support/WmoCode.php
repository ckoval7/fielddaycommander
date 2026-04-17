<?php

namespace App\Support;

class WmoCode
{
    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /** @var array<int, string> */
    private static array $dayIcons = [
        0 => 'phosphor-sun-duotone',
        1 => 'phosphor-sun-dim-duotone',
        2 => 'phosphor-cloud-sun-duotone',
        3 => 'phosphor-cloud-duotone',
        45 => 'phosphor-cloud-fog-duotone',
        48 => 'phosphor-cloud-fog-duotone',
        51 => 'phosphor-drop-half-duotone',
        53 => 'phosphor-drop-duotone',
        55 => 'phosphor-drop-simple-duotone',
        61 => 'phosphor-cloud-rain-duotone',
        63 => 'phosphor-cloud-rain-duotone',
        65 => 'phosphor-cloud-rain-duotone',
        71 => 'phosphor-cloud-snow-duotone',
        73 => 'phosphor-cloud-snow-duotone',
        75 => 'phosphor-cloud-snow-duotone',
        77 => 'phosphor-snowflake-duotone',
        80 => 'phosphor-cloud-rain-duotone',
        81 => 'phosphor-cloud-rain-duotone',
        82 => 'phosphor-cloud-rain-duotone',
        85 => 'phosphor-cloud-snow-duotone',
        86 => 'phosphor-cloud-snow-duotone',
        95 => 'phosphor-cloud-lightning-duotone',
        96 => 'phosphor-cloud-lightning-duotone',
        99 => 'phosphor-cloud-lightning-duotone',
    ];

    /** @var array<string, string> Day icon -> Night icon overrides */
    private static array $nightOverrides = [
        'phosphor-sun-duotone' => 'phosphor-moon-duotone',
        'phosphor-sun-dim-duotone' => 'phosphor-moon-stars-duotone',
        'phosphor-cloud-sun-duotone' => 'phosphor-cloud-moon-duotone',
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

    public static function icon(int $code, bool $isNight = false): string
    {
        $day = self::$dayIcons[$code] ?? 'phosphor-cloud-duotone';

        if (! $isNight) {
            return $day;
        }

        return self::$nightOverrides[$day] ?? $day;
    }

    public static function label(int $code): string
    {
        return self::$labels[$code] ?? 'Unknown';
    }

    public static function color(int $code): string
    {
        return match (true) {
            $code <= 1 => 'text-amber-500 dark:text-amber-300 drop-shadow-[0_0_8px_#f59e0b]',
            $code === 2 => 'text-slate-400 dark:text-slate-300',
            $code === 3 => 'text-slate-500 dark:text-slate-400',
            $code === 45 || $code === 48 => 'text-gray-400 dark:text-gray-300',
            $code >= 51 && $code <= 67 => 'text-blue-400 dark:text-blue-300',
            $code >= 71 && $code <= 77 => 'text-sky-200 dark:text-sky-100',
            $code >= 80 && $code <= 82 => 'text-blue-500 dark:text-blue-400',
            $code >= 85 && $code <= 86 => 'text-sky-300 dark:text-sky-200',
            $code >= 95 => 'text-yellow-400 dark:text-yellow-300',
            default => 'text-slate-400 dark:text-slate-300',
        };
    }
}
