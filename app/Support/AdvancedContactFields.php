<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use App\Models\Contact;

/**
 * The optional details an operator can add to a contact from the logging
 * screen's Advanced Fields: dial frequency, signal reports, the other
 * station's grid square and name, and notes.
 *
 * Browser sync and recalled-contact edits share these rules so a value the
 * contact queue accepts offline is accepted again when it syncs.
 */
final class AdvancedContactFields
{
    /**
     * RST (59, 599) or a digital-mode dB report (-10, +05).
     */
    public const RST_PATTERN = '/^([1-5][1-9][1-9]?|[+-]\d{1,2})$/';

    /**
     * A 4- or 6-character Maidenhead locator.
     */
    public const GRID_PATTERN = '/^[A-R]{2}\d{2}([A-X]{2})?$/i';

    public const NAME_MAX = 50;

    public const NOTES_MAX = 500;

    /**
     * Lowest and highest dial frequency accepted, in Hz (2200m to 1mm).
     */
    public const MIN_FREQUENCY_HZ = 100_000;

    public const MAX_FREQUENCY_HZ = 300_000_000_000;

    /**
     * Every field name, as stored on the contact.
     *
     * @var array<int, string>
     */
    public const FIELDS = ['frequency_hz', 'rst_sent', 'rst_received', 'grid_square', 'contact_name', 'notes'];

    private function __construct() {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'frequency_hz' => ['nullable', 'integer', 'min:'.self::MIN_FREQUENCY_HZ, 'max:'.self::MAX_FREQUENCY_HZ],
            'rst_sent' => ['nullable', 'string', 'max:4', 'regex:'.self::RST_PATTERN],
            'rst_received' => ['nullable', 'string', 'max:4', 'regex:'.self::RST_PATTERN],
            'grid_square' => ['nullable', 'string', 'max:6', 'regex:'.self::GRID_PATTERN],
            'contact_name' => ['nullable', 'string', 'max:'.self::NAME_MAX],
            'notes' => ['nullable', 'string', 'max:'.self::NOTES_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'rst_sent.regex' => 'Enter a sent report like 59, 599, or -10.',
            'rst_received.regex' => 'Enter a received report like 59, 599, or -10.',
            'grid_square.regex' => 'Enter a 4- or 6-character grid square, like FN31 or FN31pr.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'frequency_hz' => 'frequency',
            'rst_sent' => 'sent report',
            'rst_received' => 'received report',
            'grid_square' => 'grid square',
            'contact_name' => 'name',
            'notes' => 'notes',
        ];
    }

    /**
     * Trim the fields, turn blanks into null, and write the grid square the
     * usual way (FN31pr).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $normalized = [];

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                $normalized[$field] = null;

                continue;
            }

            $normalized[$field] = match ($field) {
                'rst_sent', 'rst_received' => strtoupper((string) $value),
                'grid_square' => self::formatGrid((string) $value),
                default => $value,
            };
        }

        return $normalized;
    }

    /**
     * Upper-case field and square, lower-case subsquare: "fn31PR" -> "FN31pr".
     */
    public static function formatGrid(string $grid): string
    {
        return strtoupper(substr($grid, 0, 4)).strtolower(substr($grid, 4));
    }

    /**
     * One short line for the QSO log: "14.074 · 599/579 · FN31pr · Bob".
     * Notes are left out; they're too long for a log line.
     */
    public static function summary(Contact $contact): string
    {
        $reports = $contact->rst_sent !== null || $contact->rst_received !== null
            ? ($contact->rst_sent ?? '–').'/'.($contact->rst_received ?? '–')
            : null;

        return implode(' · ', array_filter([
            self::formatMhz($contact->frequency_hz),
            $reports,
            $contact->grid_square,
            $contact->contact_name,
        ]));
    }

    /**
     * A frequency in Hz as MHz for display, without trailing zeros ("14.074").
     */
    public static function formatMhz(?int $frequencyHz): ?string
    {
        if ($frequencyHz === null) {
            return null;
        }

        return rtrim(rtrim(number_format($frequencyHz / 1_000_000, 6, '.', ''), '0'), '.');
    }
}
