<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

/**
 * Spells text in the NATO phonetic alphabet, the way it's said on the air.
 */
class Phonetics
{
    public const ALPHABET = [
        'A' => 'Alpha', 'B' => 'Bravo', 'C' => 'Charlie', 'D' => 'Delta',
        'E' => 'Echo', 'F' => 'Foxtrot', 'G' => 'Golf', 'H' => 'Hotel',
        'I' => 'India', 'J' => 'Juliet', 'K' => 'Kilo', 'L' => 'Lima',
        'M' => 'Mike', 'N' => 'November', 'O' => 'Oscar', 'P' => 'Papa',
        'Q' => 'Quebec', 'R' => 'Romeo', 'S' => 'Sierra', 'T' => 'Tango',
        'U' => 'Uniform', 'V' => 'Victor', 'W' => 'Whiskey', 'X' => 'X-ray',
        'Y' => 'Yankee', 'Z' => 'Zulu',
        '0' => 'Zero', '1' => 'One', '2' => 'Two', '3' => 'Three',
        '4' => 'Four', '5' => 'Five', '6' => 'Six', '7' => 'Seven',
        '8' => 'Eight', '9' => 'Nine',
    ];

    /**
     * Spell each character, leaving anything outside the alphabet as is.
     */
    public static function spell(string $text): string
    {
        return implode(' ', array_map(
            fn (string $char): string => self::ALPHABET[$char] ?? $char,
            str_split(strtoupper($text)),
        ));
    }
}
