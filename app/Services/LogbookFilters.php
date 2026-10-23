<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Contact;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The logbook's filter state, shared by the logbook browser and the CSV
 * export so both list exactly the same contacts.
 *
 * Parameter names match the browser's URL query string (`bandIds`,
 * `callsignSearch`, `event`, ...), so the export link carries the filters
 * on screen unchanged.
 */
final readonly class LogbookFilters
{
    /** Filter properties, keyed by their URL/query parameter name. */
    public const FILTER_KEYS = [
        'bandIds',
        'modeIds',
        'stationIds',
        'operatorIds',
        'timeFrom',
        'timeTo',
        'callsignSearch',
        'sectionIds',
        'showDuplicates',
        'showTranscribed',
        'showGota',
        'showDeleted',
        'needsAttention',
    ];

    private const ID_KEYS = ['bandIds', 'modeIds', 'stationIds', 'operatorIds', 'sectionIds'];

    /**
     * @param  list<int>  $bandIds
     * @param  list<int>  $modeIds
     * @param  list<int>  $stationIds
     * @param  list<int>  $operatorIds
     * @param  list<int>  $sectionIds
     */
    public function __construct(
        public array $bandIds = [],
        public array $modeIds = [],
        public array $stationIds = [],
        public array $operatorIds = [],
        public ?string $timeFrom = null,
        public ?string $timeTo = null,
        public ?string $callsignSearch = null,
        public array $sectionIds = [],
        public ?string $showDuplicates = null,
        public ?string $showTranscribed = null,
        public ?string $showGota = null,
        public ?string $showDeleted = null,
        public ?string $needsAttention = null,
    ) {}

    /**
     * Build filters from untrusted input (a query string or component state),
     * dropping anything that isn't the expected shape.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $values = [];

        foreach (self::FILTER_KEYS as $key) {
            $value = $input[$key] ?? null;

            $values[$key] = in_array($key, self::ID_KEYS, true)
                ? self::idList($value)
                : self::optionalString($value);
        }

        return new self(...$values);
    }

    /**
     * The non-empty filters as query parameters, named as on the browser's URL.
     *
     * @return array<string, string|list<int>>
     */
    public function toQueryParameters(): array
    {
        $parameters = [];

        foreach (self::FILTER_KEYS as $key) {
            $value = $this->{$key};

            if ($value !== null && $value !== '' && $value !== []) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * The filtered, eager-loaded contact query for one event configuration,
     * newest first.
     *
     * Deleted contacts are only reachable for people who may edit contacts,
     * matching the browser, which only offers that filter to them.
     *
     * @return Builder<Contact>
     */
    public function query(int $eventConfigurationId): Builder
    {
        return (new LogbookQueryBuilder)->applyFilters([
            'event_configuration_id' => $eventConfigurationId,
            'band_ids' => $this->bandIds,
            'mode_ids' => $this->modeIds,
            'station_ids' => $this->stationIds,
            'operator_ids' => $this->operatorIds,
            'time_from' => $this->timeFrom,
            'time_to' => $this->timeTo,
            'callsign' => $this->callsignSearch,
            'section_ids' => $this->sectionIds,
            'duplicate_filter' => $this->showDuplicates,
            'transcribed_filter' => $this->showTranscribed,
            'gota_filter' => $this->showGota,
            'deleted_filter' => Gate::allows('edit-contacts') ? $this->showDeleted : null,
            'attention_filter' => $this->needsAttention,
        ]);
    }

    /**
     * The event the logbook shows: the one named by id when it exists,
     * otherwise the current event context, otherwise the most recently
     * ended event (covers the post-event grace period).
     */
    public static function resolveEvent(?int $eventId): ?Event
    {
        $event = $eventId !== null
            ? Event::query()->with('eventConfiguration')->find($eventId)
            : null;

        $event ??= app(EventContextService::class)->getContextEvent();

        $event ??= Event::query()
            ->with('eventConfiguration')
            ->where('end_time', '<=', appNow())
            ->orderByDesc('end_time')
            ->first();

        return $event;
    }

    /**
     * @return list<int>
     */
    private static function idList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            intval(...),
            array_filter($value, fn (mixed $id): bool => is_scalar($id) && ctype_digit((string) $id)),
        ));
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
