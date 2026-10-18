<?php

namespace App\Support;

/**
 * Guards CSV exports against spreadsheet formula injection.
 *
 * Spreadsheet apps evaluate a cell that starts with =, +, -, @, tab or
 * carriage return as a formula. Prefixing such text with a single quote
 * makes the app show it as plain text instead.
 *
 * Values made only of digits, spaces and + - ( ) . (phone numbers such as
 * "+1 (555) 123-4567", or "-5") are left alone. Without letters, quotes,
 * =, @, ! or : such a cell can't name a function, reference another sheet or
 * cell, or build a link, so the worst a spreadsheet can do is evaluate it as
 * plain arithmetic ("-2+3" shows as 1). That changes how the value is
 * displayed but can't run anything, and it keeps phone numbers readable.
 */
class CsvSafe
{
    /**
     * Characters that make a spreadsheet treat a cell as a formula.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Phone-number-shaped text: at least one digit, otherwise only spaces and + - ( ) .
     */
    private const NUMERIC_OR_PHONE_PATTERN = '/^[0-9 +\-().]*[0-9][0-9 +\-().]*$/';

    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Neutralize a single cell value. Non-strings, plain numbers and phone-shaped text pass through untouched.
     */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value) || preg_match(self::NUMERIC_OR_PHONE_PATTERN, $value) === 1) {
            return $value;
        }

        $firstCharacter = ltrim($value, ' ')[0] ?? '';

        return in_array($firstCharacter, self::FORMULA_TRIGGERS, true) ? "'".$value : $value;
    }

    /**
     * Neutralize every cell in a row.
     *
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), $row);
    }

    /**
     * Write a neutralized row to an open CSV handle.
     *
     * @param  resource  $handle
     * @param  array<int|string, mixed>  $row
     */
    public static function put($handle, array $row): int|false
    {
        return fputcsv($handle, self::row($row), escape: '\\');
    }
}
