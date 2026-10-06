<?php

namespace App\Support;

class TransmitterCountGenerator
{
    /**
     * Relative weights for a Class A transmitter count. Most clubs run 4-5
     * transmitters, 1A is fairly common, and 10+ gets rarer as it climbs.
     *
     * @var array<int, int>
     */
    private const CLASS_A_WEIGHTS = [
        1 => 100,
        2 => 90,
        3 => 130,
        4 => 180,
        5 => 160,
        6 => 90,
        7 => 60,
        8 => 40,
        9 => 30,
        10 => 12,
        11 => 8,
        12 => 6,
        13 => 4,
        14 => 3,
        15 => 2,
        16 => 2,
        17 => 1,
        18 => 1,
        19 => 1,
        20 => 1,
    ];

    /**
     * Pick a plausible transmitter count for the given class letter.
     */
    public static function forClass(string $letter): int
    {
        return match ($letter) {
            'A' => self::weightedPick(self::CLASS_A_WEIGHTS),
            'F', 'O' => random_int(2, 10),
            'B', 'I' => random_int(1, 2),
            default => 1,
        };
    }

    /**
     * @param  array<int, int>  $weights
     */
    private static function weightedPick(array $weights): int
    {
        $roll = random_int(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }
}
