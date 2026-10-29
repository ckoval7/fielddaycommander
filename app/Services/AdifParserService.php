<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

class AdifParserService
{
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

        $eohPos = stripos($content, '<EOH>');
        if ($eohPos !== false) {
            $headerText = substr($content, 0, $eohPos);
            $result['header'] = $this->extractTags($headerText);
            $body = substr($content, $eohPos + 5);
        } else {
            $body = $content;
        }

        if (isset($result['header']['ADIF_VER']) && str_starts_with($result['header']['ADIF_VER'], '3')) {
            $result['version'] = 3;
        }

        $recordChunks = preg_split('/<EOR>/i', $body);
        foreach ($recordChunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $tags = $this->extractTags($chunk);
            if (! empty($tags)) {
                $result['records'][] = $tags;
            }
        }

        return $result;
    }

    /**
     * Extract tag-value pairs from an ADIF text segment.
     *
     * Supports both ADIF 2 (<TAG:LEN>VALUE) and ADIF 3 (<TAG:LEN:TYPE>VALUE) formats.
     *
     * @return array<string, string>
     */
    private function extractTags(string $text): array
    {
        $tags = [];

        preg_match_all('/<(\w+):(\d+)(?::[A-Za-z])?>/', $text, $matches, PREG_OFFSET_CAPTURE);

        for ($i = 0; $i < count($matches[0]); $i++) {
            $tagName = strtoupper($matches[1][$i][0]);
            $length = (int) $matches[2][$i][0];
            $valueStart = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $valueEnd = $matches[0][$i + 1][1] ?? strlen($text);
            $tags[$tagName] = trim($this->readValue(substr($text, $valueStart, $valueEnd - $valueStart), $length));
        }

        return $tags;
    }

    /**
     * The first $length units of the text before the next tag. Loggers
     * disagree on whether ADIF lengths count characters or UTF-8 bytes, so
     * valid UTF-8 is read by character; stopping at the next tag keeps a
     * byte count from running into the following field.
     */
    private function readValue(string $segment, int $length): string
    {
        if (mb_check_encoding($segment, 'UTF-8')) {
            return mb_substr($segment, 0, $length, 'UTF-8');
        }

        return substr($segment, 0, $length);
    }
}
