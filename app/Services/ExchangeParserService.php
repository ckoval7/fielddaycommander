<?php

namespace App\Services;

use App\Models\Section;

class ExchangeParserService
{
    /** @var array<string, int>|null */
    private ?array $sectionCache = null;

    /**
     * Parse a complete exchange string into structured data.
     *
     * @return array{success: bool, callsign: ?string, transmitter_count: ?int, class_code: ?string, section_code: ?string, section_id: ?int, errors: array<string>}
     */
    public function parse(string $input): array
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

        $input = trim($input);
        if ($input === '') {
            $result['errors'][] = 'Exchange is empty';

            return $result;
        }

        $tokens = preg_split('/\s+/', strtoupper($input));

        if (count($tokens) < 3) {
            $result['errors'][] = 'Exchange must contain callsign, class, and section (e.g. W1AW 3A CT)';

            return $result;
        }

        if (count($tokens) > 3) {
            $result['errors'][] = 'Too many parts in exchange';

            return $result;
        }

        // Token 1: Callsign
        $callsign = $tokens[0];
        if (! $this->isValidCallsign($callsign)) {
            $result['errors'][] = "Invalid callsign: {$callsign}";

            return $result;
        }
        $result['callsign'] = $callsign;

        // Token 2: Exchange class (e.g. "3A", "1D", "15F")
        if (! preg_match('/^(\d{1,2})([A-F])$/i', $tokens[1], $matches)) {
            $result['errors'][] = "Invalid class: {$tokens[1]} (expected format like 3A, 1D)";

            return $result;
        }
        $result['transmitter_count'] = (int) $matches[1];
        $result['class_code'] = strtoupper($matches[2]);

        // Token 3: Section code
        $sectionCode = $tokens[2];
        $sectionId = $this->lookupSection($sectionCode);
        if ($sectionId === null) {
            $result['errors'][] = "Unknown section: {$sectionCode}";

            return $result;
        }
        $result['section_code'] = $sectionCode;
        $result['section_id'] = $sectionId;

        $result['success'] = true;

        return $result;
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
        if (strlen($callsign) < 3 || strlen($callsign) > 10) {
            return false;
        }

        if (! preg_match('/^[A-Z0-9\/]+$/', $callsign)) {
            return false;
        }

        // Must contain at least one digit and one letter
        if (! preg_match('/[0-9]/', $callsign)) {
            return false;
        }

        if (! preg_match('/[A-Z]/', $callsign)) {
            return false;
        }

        return true;
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
