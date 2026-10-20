<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\NetLog\Concerns;

use App\Services\NetLogExchangeParser;

/**
 * Tactical name suggestions as net control types a line: the called
 * station first, then the calling station.
 */
trait HasNetSuggestions
{
    private const MAX_SUGGESTIONS = 5;

    public function updatedExchangeInput(): void
    {
        $this->parseError = null;
        $this->suggestions = $this->buildSuggestions($this->exchangeInput);
    }

    public function selectSuggestion(string $exchange): void
    {
        $this->exchangeInput = $exchange;
        $this->suggestions = [];
        $this->dispatch('suggestion-selected');
    }

    /**
     * Suggest tactical names for the station being typed: the called
     * station first, then the calling station.
     *
     * @return list<array{exchange: string, worked_on: string}>
     */
    protected function buildSuggestions(string $input): array
    {
        $input = ltrim((string) preg_replace('/\s+/', ' ', $input));

        if ($input === '' || $this->tacticalNames === []) {
            return [];
        }

        // Still typing the called station?
        $matches = $this->namesStartingWith($input);

        if ($matches === []) {
            // Then it's the calling station, after the called one.
            [$to, $afterTo] = app(NetLogExchangeParser::class)->takeStation($input, $this->tacticalNames);
            $matches = $afterTo === '' ? [] : $this->namesStartingWith($afterTo);
            $prefix = $to.' ';
        } else {
            $prefix = '';
        }

        return collect($matches)
            ->take(self::MAX_SUGGESTIONS)
            ->map(fn (string $name) => [
                'exchange' => $prefix.$name.' ',
                'worked_on' => $this->resolveCallsign($name) ?? 'Nobody on duty',
            ])
            ->values()
            ->all();
    }

    /**
     * Tactical names that start with, but don't already equal, what's typed.
     *
     * @return list<string>
     */
    protected function namesStartingWith(string $partial): array
    {
        $partial = mb_strtolower($partial);

        return array_values(array_filter(
            $this->tacticalNames,
            fn (string $name) => str_starts_with(mb_strtolower($name), $partial) && mb_strtolower($name) !== rtrim($partial),
        ));
    }
}
