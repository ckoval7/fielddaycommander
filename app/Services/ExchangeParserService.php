<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\OperatingClass;
use App\Models\Section;

class ExchangeParserService
{
    /**
     * Class letters valid in any supported rulebook's exchange.
     *
     * A-F are ARRL Field Day; H (Home), I (Indoor), O (Outdoor) and M (Mobile)
     * are Winter Field Day.
     *
     * Public so the validation rules that guard the same exchange field share
     * one source of truth rather than restating the pattern and drifting.
     */
    public const EXCHANGE_CLASS_CODES = '[A-FHIMO]';

    /**
     * Validation regex for a full exchange class token, e.g. "3A" or "2M".
     */
    public const EXCHANGE_CLASS_PATTERN = '/^\d{1,2}'.self::EXCHANGE_CLASS_CODES.'$/i';

    /** @var array<string, int>|null */
    private ?array $sectionCache = null;

    /** @var array<int, array<int, string>> */
    private array $classCodeCache = [];

    /**
     * Parse a complete exchange string into structured data.
     *
     * Supplying $eventTypeId restricts the accepted class letters to those the
     * event type's rulebook defines; omitting it — or supplying a type with no
     * operating classes configured — accepts any known class.
     *
     * @return array{success: bool, callsign: ?string, transmitter_count: ?int, class_code: ?string, section_code: ?string, section_id: ?int, errors: array<string>}
     */
    public function parse(string $input, ?int $eventTypeId = null): array
    {
        $result = [
            'success' => false,
            'callsign' => null,
            'transmitter_count' => null,
            'class_code' => null,
            'section_code' => null,
            'section_id' => null,
            'errors' => [],
        ];

        $tokens = $this->tokenize($input, $result['errors']);

        if ($tokens !== null
            && $this->applyCallsign($tokens[0], $result)
            && $this->applyExchangeClass($tokens[1], $eventTypeId, $result)
            && $this->applySection($tokens[2], $result)) {
            $result['success'] = true;
        }

        return $result;
    }

    /**
     * Split an exchange into its three uppercased tokens.
     *
     * @param  array<string>  $errors
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function tokenize(string $input, array &$errors): ?array
    {
        $input = trim($input);

        if ($input === '') {
            $errors[] = 'Enter callsign, class, and section';

            return null;
        }

        $tokens = preg_split('/\s+/', strtoupper($input));

        if (count($tokens) !== 3) {
            $errors[] = count($tokens) < 3
                ? 'Enter callsign, class, and section (e.g. W1AW 3A CT)'
                : 'Use only callsign, class, and section';

            return null;
        }

        return $tokens;
    }

    /**
     * Validate the callsign token and record it on the result.
     *
     * @param  array{success: bool, callsign: ?string, transmitter_count: ?int, class_code: ?string, section_code: ?string, section_id: ?int, errors: array<string>}  $result
     */
    private function applyCallsign(string $callsign, array &$result): bool
    {
        if (! $this->isValidCallsign($callsign)) {
            $result['errors'][] = "Invalid callsign: {$callsign}";

            return false;
        }

        $result['callsign'] = $callsign;

        return true;
    }

    /**
     * Validate the exchange class token (e.g. "3A", "1D", "2M") and record it.
     *
     * The shape is checked against the union of every rulebook's class letters;
     * which of those letters are actually legal is decided by the event type
     * when one is supplied, so a Field Day event rejects WFD's H/I/O/M and vice
     * versa.
     *
     * @param  array{success: bool, callsign: ?string, transmitter_count: ?int, class_code: ?string, section_code: ?string, section_id: ?int, errors: array<string>}  $result
     */
    private function applyExchangeClass(string $token, ?int $eventTypeId, array &$result): bool
    {
        if (! preg_match('/^(\d{1,2})('.self::EXCHANGE_CLASS_CODES.')$/i', $token, $matches)) {
            $result['errors'][] = "Invalid class: {$token} (use a number and class letter, e.g. 3A or 2M)";

            return false;
        }

        $classCode = strtoupper($matches[2]);
        $validClassCodes = $eventTypeId === null ? [] : $this->validClassCodes($eventTypeId);

        if ($validClassCodes !== [] && ! in_array($classCode, $validClassCodes, true)) {
            $result['errors'][] = "Class {$classCode} is not valid for this event";

            return false;
        }

        $result['transmitter_count'] = (int) $matches[1];
        $result['class_code'] = $classCode;

        return true;
    }

    /**
     * Resolve the section token to a section id and record it.
     *
     * @param  array{success: bool, callsign: ?string, transmitter_count: ?int, class_code: ?string, section_code: ?string, section_id: ?int, errors: array<string>}  $result
     */
    private function applySection(string $sectionCode, array &$result): bool
    {
        $sectionId = $this->lookupSection($sectionCode);

        if ($sectionId === null) {
            $result['errors'][] = "Unknown section: {$sectionCode}";

            return false;
        }

        $result['section_code'] = $sectionCode;
        $result['section_id'] = $sectionId;

        return true;
    }

    /**
     * Extract a callsign from partial input for real-time dupe checking.
     */
    public function extractCallsign(string $partial): ?string
    {
        $partial = trim($partial);
        if ($partial === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', strtoupper($partial));
        $candidate = $tokens[0];

        if ($this->isValidCallsign($candidate)) {
            return $candidate;
        }

        return null;
    }

    /**
     * Validate a callsign format.
     * Must be 3-10 chars, contain at least one digit and one letter.
     */
    private function isValidCallsign(string $callsign): bool
    {
        $length = strlen($callsign);

        return $length >= 3
            && $length <= 10
            && preg_match('/^[A-Z0-9\/]+$/', $callsign)
            && preg_match('/\d/', $callsign)
            && preg_match('/[A-Z]/', $callsign);
    }

    /**
     * Class codes this event type's rulebook allows, upper-cased.
     *
     * @return array<int, string>
     */
    private function validClassCodes(int $eventTypeId): array
    {
        if (! array_key_exists($eventTypeId, $this->classCodeCache)) {
            $this->classCodeCache[$eventTypeId] = OperatingClass::query()
                ->where('event_type_id', $eventTypeId)
                ->pluck('code')
                ->map(fn (string $code): string => strtoupper($code))
                ->all();
        }

        return $this->classCodeCache[$eventTypeId];
    }

    /**
     * Look up a section code and return its ID.
     */
    private function lookupSection(string $code): ?int
    {
        if ($this->sectionCache === null) {
            $this->sectionCache = Section::query()
                ->where('is_active', true)
                ->pluck('id', 'code')
                ->toArray();
        }

        return $this->sectionCache[strtoupper($code)] ?? null;
    }
}
