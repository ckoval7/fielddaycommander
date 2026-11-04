<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Support\CsvSafe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Reads and writes a bulletin transmission schedule as CSV, so a schedule
 * published ahead of an event can be downloaded once and loaded into any
 * Field Day Commander server without typing it in.
 *
 * Columns: time_utc, mode, frequencies, source, notes. Times are UTC in
 * "YYYY-MM-DD HH:MM" (or ISO 8601) form; frequencies are MHz, comma separated.
 */
class BulletinScheduleCsvService
{
    /**
     * Column order written on export and documented for imports.
     *
     * @var list<string>
     */
    public const COLUMNS = ['time_utc', 'mode', 'frequencies', 'source', 'notes'];

    /**
     * Largest number of transmissions one file may hold.
     */
    public const MAX_ROWS = 500;

    /**
     * How times are written on export and in messages.
     */
    public const TIME_FORMAT = 'Y-m-d H:i';

    /**
     * How far outside the event's start and end a transmission may fall,
     * to tolerate small mistakes in the event's configured times.
     */
    private const EVENT_MARGIN_HOURS = 24;

    /**
     * Header spellings accepted for each column, after lowercasing and
     * stripping everything but letters.
     *
     * @var array<string, list<string>>
     */
    private const HEADER_ALIASES = [
        'time_utc' => ['timeutc', 'time', 'utc', 'datetime', 'datetimeutc', 'scheduledat', 'start', 'startutc'],
        'mode' => ['mode'],
        'frequencies' => ['frequencies', 'frequency', 'frequenciesmhz', 'frequencymhz', 'freq', 'freqs'],
        'source' => ['source', 'station', 'callsign', 'sender'],
        'notes' => ['notes', 'note', 'comments', 'comment'],
    ];

    /**
     * Mode spellings accepted in the mode column.
     *
     * @var array<string, string>
     */
    private const MODE_ALIASES = [
        'cw' => 'cw',
        'morse' => 'cw',
        'digital' => 'digital',
        'digi' => 'digital',
        'data' => 'digital',
        'rtty' => 'digital',
        'psk' => 'digital',
        'psk31' => 'digital',
        'mfsk' => 'digital',
        'phone' => 'phone',
        'voice' => 'phone',
        'ssb' => 'phone',
    ];

    /**
     * Parse an uploaded schedule for an event.
     *
     * @param  list<string>  $allowedSources  The rulebook's named senders; empty allows any callsign.
     * @return array{rows: list<array{scheduled_at: CarbonImmutable, mode: string, frequencies: string, source: string, notes: ?string}>, errors: list<string>}
     */
    public function parse(string $contents, Event $event, array $allowedSources, string $defaultSource): array
    {
        $lines = $this->splitRecords($contents);

        if ($lines === []) {
            return ['rows' => [], 'errors' => ['The file is empty.']];
        }

        $columnMap = $this->mapHeader(array_shift($lines));
        $missing = array_diff(['time_utc', 'mode', 'frequencies'], array_keys($columnMap));

        if ($missing !== []) {
            return ['rows' => [], 'errors' => [
                'A header row is required. Missing: '.implode(', ', $missing).'.',
            ]];
        }

        $rows = [];
        $errors = [];
        $rowCount = 0;

        foreach ($lines as $index => $fields) {
            $lineNumber = $index + 2;

            if ($this->isBlank($fields)) {
                continue;
            }

            if (++$rowCount > self::MAX_ROWS) {
                $errors[] = 'The file has more than '.self::MAX_ROWS.' transmissions.';
                break;
            }

            $value = fn (string $column): string => isset($columnMap[$column])
                ? $this->clean($fields[$columnMap[$column]] ?? '')
                : '';

            $result = $this->parseRow([
                'time_utc' => $value('time_utc'),
                'mode' => $value('mode'),
                'frequencies' => $value('frequencies'),
                'source' => $value('source'),
                'notes' => $value('notes'),
            ], $event, $allowedSources, $defaultSource);

            if (is_string($result)) {
                $errors[] = "Row {$lineNumber}: {$result}";
            } else {
                $rows[] = $result;
            }
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'The file has no transmissions.';
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Write an event's schedule as CSV.
     *
     * @param  Collection<int, BulletinScheduleEntry>  $entries
     * @param  resource  $handle
     */
    public function write(Collection $entries, $handle): void
    {
        fputcsv($handle, self::COLUMNS, escape: '\\');

        foreach ($entries as $entry) {
            CsvSafe::put($handle, [
                $entry->scheduled_at->utc()->format(self::TIME_FORMAT),
                $entry->mode,
                $entry->frequencies,
                $entry->source,
                $entry->notes ?? '',
            ]);
        }
    }

    /**
     * Save parsed rows to an event's schedule, all or nothing. Rows already on
     * the schedule, or repeated within the file, are skipped.
     *
     * @param  list<array{scheduled_at: CarbonImmutable, mode: string, frequencies: string, source: string, notes: ?string}>  $rows
     * @return array{added: int, skipped: int}
     */
    public function store(array $rows, Event $event, bool $replace, ?int $createdBy): array
    {
        return DB::transaction(function () use ($rows, $event, $replace, $createdBy): array {
            if ($replace) {
                BulletinScheduleEntry::forEvent($event->id)->delete();
            }

            $seen = BulletinScheduleEntry::forEvent($event->id)
                ->get()
                ->map(fn (BulletinScheduleEntry $entry): string => $this->scheduleKey($entry->scheduled_at->format(self::TIME_FORMAT), $entry->mode, $entry->source))
                ->flip()
                ->all();

            $added = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $key = $this->scheduleKey($row['scheduled_at']->format(self::TIME_FORMAT), $row['mode'], $row['source']);

                if (isset($seen[$key])) {
                    $skipped++;

                    continue;
                }

                $seen[$key] = true;

                BulletinScheduleEntry::create([
                    ...$row,
                    'event_id' => $event->id,
                    'created_by' => $createdBy,
                ]);
                $added++;
            }

            return ['added' => $added, 'skipped' => $skipped];
        });
    }

    private function scheduleKey(string $time, string $mode, string $source): string
    {
        return "{$time}|{$mode}|".strtoupper($source);
    }

    /**
     * Validate one row, returning the entry data or an error message.
     *
     * @param  array{time_utc: string, mode: string, frequencies: string, source: string, notes: string}  $cells
     * @param  list<string>  $allowedSources
     * @return array{scheduled_at: CarbonImmutable, mode: string, frequencies: string, source: string, notes: ?string}|string
     */
    private function parseRow(array $cells, Event $event, array $allowedSources, string $defaultSource): array|string
    {
        try {
            $row = [
                'scheduled_at' => $this->rowTime($cells['time_utc'], $event),
                'mode' => $this->rowMode($cells['mode']),
                'frequencies' => $this->rowFrequencies($cells['frequencies']),
                'source' => $this->rowSource($cells['source'], $allowedSources, $defaultSource),
                'notes' => $cells['notes'] === '' ? null : $cells['notes'],
            ];
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        if (mb_strlen($cells['notes']) > 500) {
            return 'notes must be 500 characters or fewer.';
        }

        return $row;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function rowTime(string $time, Event $event): CarbonImmutable
    {
        $scheduledAt = $this->parseTime($time);

        if ($scheduledAt === null) {
            throw new InvalidArgumentException($time === ''
                ? 'the time is missing.'
                : "\"{$time}\" isn't a valid UTC time. Use YYYY-MM-DD HH:MM or ISO 8601.");
        }

        if (! $this->isDuringEvent($scheduledAt, $event)) {
            throw new InvalidArgumentException("{$scheduledAt->format(self::TIME_FORMAT)} UTC is not during {$event->name}.");
        }

        return $scheduledAt;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function rowMode(string $mode): string
    {
        $normalizedMode = self::MODE_ALIASES[strtolower($mode)] ?? null;

        if ($normalizedMode === null) {
            throw new InvalidArgumentException($mode === ''
                ? 'the mode is missing.'
                : "\"{$mode}\" isn't a mode. Use CW, Digital, or Phone.");
        }

        return $normalizedMode;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function rowFrequencies(string $frequencies): string
    {
        $normalizedFrequencies = $this->normalizeFrequencies($frequencies);

        if ($normalizedFrequencies === null) {
            throw new InvalidArgumentException($frequencies === ''
                ? 'the frequencies are missing.'
                : "\"{$frequencies}\" isn't a valid frequency list. Use comma-separated frequencies in MHz.");
        }

        return $normalizedFrequencies;
    }

    /**
     * @param  list<string>  $allowedSources
     *
     * @throws InvalidArgumentException
     */
    private function rowSource(string $source, array $allowedSources, string $defaultSource): string
    {
        $source = strtoupper($source) ?: $defaultSource;

        if ($source === '') {
            throw new InvalidArgumentException('the source is missing.');
        }

        if ($allowedSources !== [] && ! in_array($source, $allowedSources, true)) {
            throw new InvalidArgumentException("\"{$source}\" isn't a sender for this event. Use ".implode(' or ', $allowedSources).'.');
        }

        if (strlen($source) > 10) {
            throw new InvalidArgumentException("\"{$source}\" exceeds the 10-character source limit.");
        }

        return $source;
    }

    /**
     * Split the file into CSV records, dropping a UTF-8 byte order mark.
     *
     * @return list<list<string>>
     */
    private function splitRecords(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $records = [];

        while (($fields = fgetcsv($handle, escape: '\\')) !== false) {
            $records[] = array_map(fn (?string $field): string => (string) $field, $fields);
        }

        fclose($handle);

        while ($records !== [] && $this->isBlank($records[0])) {
            array_shift($records);
        }

        return $records;
    }

    /**
     * Map known columns to their position in the header row.
     *
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $map = [];

        foreach ($header as $position => $name) {
            $key = preg_replace('/[^a-z]/', '', strtolower($name));

            foreach (self::HEADER_ALIASES as $column => $aliases) {
                if (! isset($map[$column]) && in_array($key, $aliases, true)) {
                    $map[$column] = $position;
                }
            }
        }

        return $map;
    }

    /**
     * Parse a UTC time in "YYYY-MM-DD HH:MM" or ISO 8601 form.
     *
     * Only year-first dates are accepted, since 06/07 means June 7 in some
     * countries and July 6 in others.
     */
    private function parseTime(string $time): ?CarbonImmutable
    {
        $isValid = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}(:\d{2})?\s*(?<zone>\S*)$/', $time, $matches) === 1
            && (in_array(strtoupper($matches['zone']), ['', 'Z', 'UTC'], true)
                || preg_match('/^[+-]\d{2}:?\d{2}$/', $matches['zone']) === 1);

        if (! $isValid) {
            return null;
        }

        try {
            return CarbonImmutable::parse(preg_replace('/\s*UTC$/i', 'Z', $time), 'UTC')->utc()->seconds(0);
        } catch (Throwable) {
            return null;
        }
    }

    private function isDuringEvent(CarbonImmutable $scheduledAt, Event $event): bool
    {
        if (! $event->start_time || ! $event->end_time) {
            return true;
        }

        return $scheduledAt->between(
            $event->start_time->copy()->subHours(self::EVENT_MARGIN_HOURS),
            $event->end_time->copy()->addHours(self::EVENT_MARGIN_HOURS),
        );
    }

    /**
     * Tidy a frequency list to "7.0475, 14.0475", or null if any part isn't a number.
     */
    private function normalizeFrequencies(string $frequencies): ?string
    {
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/[,;\/]+|\s{2,}/', $frequencies) ?: []),
            fn (string $part): bool => $part !== '',
        ));

        if ($parts === []) {
            return null;
        }

        foreach ($parts as $part) {
            if (preg_match('/^\d+(\.\d+)?$/', $part) !== 1) {
                return null;
            }
        }

        $joined = implode(', ', $parts);

        return strlen($joined) <= 255 ? $joined : null;
    }

    /**
     * Trim a cell and undo the quote an export adds in front of formula-like text.
     */
    private function clean(string $value): string
    {
        $value = trim($value);

        return preg_match("/^'[=+\-@]/", $value) === 1 ? substr($value, 1) : $value;
    }

    /**
     * @param  list<string>  $fields
     */
    private function isBlank(array $fields): bool
    {
        return implode('', array_map('trim', $fields)) === '';
    }
}
