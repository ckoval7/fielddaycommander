<?php

namespace App\Support;

class CallsignGenerator
{
    private const US_SINGLE_PREFIXES = ['W', 'K', 'N'];

    private const US_DOUBLE_PREFIXES = [
        'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI', 'AJ', 'AK', 'AL',
        'KA', 'KB', 'KC', 'KD', 'KE', 'KF', 'KG', 'KH', 'KI', 'KJ', 'KK', 'KL',
        'KM', 'KN', 'KO', 'KP', 'KQ', 'KR', 'KS', 'KT', 'KU', 'KV', 'KW', 'KX', 'KY', 'KZ',
        'NA', 'NB', 'NC', 'ND', 'NE', 'NF', 'NG', 'NH', 'NI', 'NJ', 'NK', 'NL',
        'NM', 'NN', 'NO', 'NP', 'NQ', 'NR', 'NS', 'NT', 'NU', 'NV', 'NW', 'NX', 'NY', 'NZ',
        'WA', 'WB', 'WC', 'WD', 'WE', 'WF', 'WG', 'WH', 'WI', 'WJ', 'WK', 'WL',
        'WM', 'WN', 'WO', 'WP', 'WQ', 'WR', 'WS', 'WT', 'WU', 'WV', 'WW', 'WX', 'WY', 'WZ',
    ];

    /** @var array<string, list<string>> */
    private const CANADIAN_PREFIXES = [
        'VE' => ['1', '2', '3', '4', '5', '6', '7', '9'],
        'VA' => ['2', '3', '4', '5', '6', '7'],
        'VY' => ['1', '2'],
    ];

    public static function us(): string
    {
        $district = (string) random_int(0, 9);

        if (random_int(1, 10) <= 6) {
            $prefix = self::US_SINGLE_PREFIXES[array_rand(self::US_SINGLE_PREFIXES)];
        } else {
            $prefix = self::US_DOUBLE_PREFIXES[array_rand(self::US_DOUBLE_PREFIXES)];
        }

        $suffixLength = [1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 3][random_int(1, 5)];
        $suffix = '';
        for ($i = 0; $i < $suffixLength; $i++) {
            $suffix .= chr(random_int(65, 90));
        }

        return $prefix.$district.$suffix;
    }

    public static function canada(): string
    {
        $prefixKey = array_rand(self::CANADIAN_PREFIXES);
        $districts = self::CANADIAN_PREFIXES[$prefixKey];
        $district = $districts[array_rand($districts)];

        $suffix = '';
        for ($i = 0; $i < random_int(2, 3); $i++) {
            $suffix .= chr(random_int(65, 90));
        }

        return $prefixKey.$district.$suffix;
    }

    public static function any(): string
    {
        return random_int(1, 100) <= 85 ? self::us() : self::canada();
    }
}
