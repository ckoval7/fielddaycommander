<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

class AdifParserService
{
    /**
     * An ADIF tag at the current offset: <NAME>, <NAME:LEN> or <NAME:LEN:T>.
     */
    private const TAG_PATTERN = '/\G<(\w+)(?::(\d+)(?::[A-Za-z])?)?>/';

    /**
     * The next data tag anywhere after the offset.
     */
    private const TAG_PATTERN_ANYWHERE = '/<(\w+):(\d+)(?::[A-Za-z])?>|<eo[hr]>/i';

    /**
     * Parse an ADIF string into structured data.
     *
     * @return array{version: int, header: array<string, string>, records: array<int, array<string, string>>, errors: array<string>}
     */
    public function parse(string $content): array
    {
        $result = [
            'version' => 2,
            'header' => [],
            'records' => [],
            'errors' => [],
        ];

        $content = trim($content);
        if ($content === '') {
            $result['errors'][] = 'ADIF content is empty';

            return $result;
        }

        $current = [];

        foreach ($this->scan($content) as [$tagName, $value]) {
            if ($tagName === 'EOH') {
                $result['header'] = $current;
                $current = [];
            } elseif ($tagName === 'EOR') {
                if ($current !== []) {
                    $result['records'][] = $current;
                }
                $current = [];
            } else {
                $current[$tagName] = $value;
            }
        }

        // A last record without <EOR> still counts.
        if ($current !== []) {
            $result['records'][] = $current;
        }

        if (isset($result['header']['ADIF_VER']) && str_starts_with($result['header']['ADIF_VER'], '3')) {
            $result['version'] = 3;
        }

        return $result;
    }

    /**
     * Walk the text tag by tag, reading each value by its declared length,
     * so "<EOR>" or a tag-like string inside a value is read as text.
     * Yields [TAG, value] pairs, with [EOH, ''] and [EOR, ''] markers.
     *
     * @return \Generator<int, array{string, string}>
     */
    private function scan(string $text): \Generator
    {
        $position = 0;
        $length = strlen($text);

        while (($open = strpos($text, '<', $position)) !== false) {
            if (preg_match(self::TAG_PATTERN, $text, $match, 0, $open) !== 1) {
                $position = $open + 1;

                continue;
            }

            $tagName = strtoupper($match[1]);
            $valueStart = $open + strlen($match[0]);

            if (! isset($match[2]) || $match[2] === '') {
                $position = $valueStart;

                if ($tagName === 'EOH' || $tagName === 'EOR') {
                    yield [$tagName, ''];
                }

                continue;
            }

            [$value, $position] = $this->readValue($text, $valueStart, (int) $match[2], $length);

            yield [$tagName, trim($this->toUtf8($value))];
        }
    }

    /**
     * The value starting at $start and the offset just after it. Loggers
     * disagree on whether ADIF lengths count characters or UTF-8 bytes, so
     * the reading that ends where the next tag (or the end) begins wins;
     * when neither does, the value stops at the next tag, so a wrong length
     * never runs into the following field.
     *
     * @return array{string, int}
     */
    private function readValue(string $text, int $start, int $declared, int $length): array
    {
        $byBytes = substr($text, $start, $declared);

        if ($this->endsAtBoundary($text, $start + strlen($byBytes), $length)) {
            return [$byBytes, $start + strlen($byBytes)];
        }

        $rest = substr($text, $start);

        if (mb_check_encoding($rest, 'UTF-8')) {
            $byCharacters = mb_substr($rest, 0, $declared, 'UTF-8');

            if ($this->endsAtBoundary($text, $start + strlen($byCharacters), $length)) {
                return [$byCharacters, $start + strlen($byCharacters)];
            }
        }

        $nextTag = preg_match(self::TAG_PATTERN_ANYWHERE, $text, $match, PREG_OFFSET_CAPTURE, $start) === 1
            ? $match[0][1]
            : $length;
        $segment = substr($text, $start, $nextTag - $start);

        $value = mb_check_encoding($segment, 'UTF-8')
            ? mb_substr($segment, 0, $declared, 'UTF-8')
            : substr($segment, 0, $declared);

        return [$value, $nextTag];
    }

    /**
     * Whether only whitespace lies between $offset and the next tag or the end.
     */
    private function endsAtBoundary(string $text, int $offset, int $length): bool
    {
        while ($offset < $length && ctype_space($text[$offset])) {
            $offset++;
        }

        return $offset >= $length || preg_match(self::TAG_PATTERN, $text, $match, 0, $offset) === 1;
    }

    /**
     * Text from a PC set to a legacy Windows code page arrives as bytes that
     * are not UTF-8; read those as Windows-1252, the code page Windows
     * loggers use in the US and Western Europe.
     */
    private function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
