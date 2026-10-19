<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Enums\NetPriority;
use InvalidArgumentException;

/**
 * Parses a net log line: "[calling station] [called station] [message]".
 *
 * Stations are callsigns or tactical names. Tactical names can have spaces
 * ("Aid 7", "Net Control"), so known names are matched first, longest
 * first, and anything else is one word. A message starting with EMERGENCY
 * or PRIORITY sets the entry's priority.
 */
class NetLogExchangeParser
{
    /**
     * @param  list<string>  $knownNames  Tactical names in use at the event
     * @return array{from: string, to: string, message: string, priority: NetPriority|null}
     *
     * @throws InvalidArgumentException when the line is missing a station
     */
    public function parse(string $line, array $knownNames = []): array
    {
        $rest = trim((string) preg_replace('/\s+/', ' ', $line));

        [$from, $rest] = $this->takeStation($rest, $knownNames);
        [$to, $rest] = $this->takeStation($rest, $knownNames);

        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Type the calling station, then your call, then the message. For example: AID7 NCS WATER LOW');
        }

        [$priority, $message] = $this->takePriority($rest);

        return [
            'from' => $from,
            'to' => $to,
            'message' => $message,
            'priority' => $priority,
        ];
    }

    /**
     * The station at the start of the line and what follows it.
     *
     * @param  list<string>  $knownNames
     * @return array{0: string|null, 1: string}
     */
    public function takeStation(string $rest, array $knownNames): array
    {
        if ($rest === '') {
            return [null, ''];
        }

        $names = $knownNames;
        usort($names, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($names as $name) {
            $length = mb_strlen($name);
            $next = mb_substr($rest, $length, 1);

            if ($length > 0 && mb_strtolower(mb_substr($rest, 0, $length)) === mb_strtolower($name) && ($next === '' || $next === ' ')) {
                return [$name, ltrim(mb_substr($rest, $length))];
            }
        }

        $parts = explode(' ', $rest, 2);

        return [mb_strtoupper($parts[0]), $parts[1] ?? ''];
    }

    /**
     * @return array{0: NetPriority|null, 1: string}
     */
    protected function takePriority(string $message): array
    {
        if (preg_match('/^(EMERGENCY|PRIORITY)\b\s*(.*)$/is', $message, $matches)) {
            return [NetPriority::from(strtolower($matches[1])), $matches[2]];
        }

        return [null, $message];
    }
}
