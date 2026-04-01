<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

class LogbookQueryBuilder
{
    /**
     * Build a query for browsing contacts with eager loading.
     */
    public function buildQuery(): Builder
    {
        return Contact::query()
            ->with([
                'band',
                'mode',
                'section',
                'logger',
                'operatingSession.station',
                'gotaOperator',
            ]);
    }

    /**
     * Filter by event configuration.
     */
    public function forEvent(Builder $query, int $eventConfigurationId): Builder
    {
        return $query->where('event_configuration_id', $eventConfigurationId);
    }

    /**
     * Filter by one or more bands.
     *
     * @param  int[]  $bandIds
     */
    public function forBand(Builder $query, array $bandIds): Builder
    {
        if (empty($bandIds)) {
            return $query;
        }

        return $query->whereIn('band_id', $bandIds);
    }

    /**
     * Filter by one or more modes.
     *
     * @param  int[]  $modeIds
     */
    public function forMode(Builder $query, array $modeIds): Builder
    {
        if (empty($modeIds)) {
            return $query;
        }

        return $query->whereIn('mode_id', $modeIds);
    }

    /**
     * Filter by one or more stations (through operating session).
     *
     * @param  int[]  $stationIds
     */
    public function forStation(Builder $query, array $stationIds): Builder
    {
        if (empty($stationIds)) {
            return $query;
        }

        return $query->whereHas('operatingSession', function (Builder $q) use ($stationIds) {
            $q->whereIn('station_id', $stationIds);
        });
    }

    /**
     * Filter by one or more operators (logger).
     *
     * @param  int[]  $userIds
     */
    public function forOperator(Builder $query, array $userIds): Builder
    {
        if (empty($userIds)) {
            return $query;
        }

        return $query->whereIn('logger_user_id', $userIds);
    }

    /**
     * Filter by time range.
     */
    public function forTimeRange(Builder $query, ?string $fromTime, ?string $toTime): Builder
    {
        if ($fromTime !== null) {
            $query->where('qso_time', '>=', $fromTime);
        }

        if ($toTime !== null) {
            $query->where('qso_time', '<=', $toTime);
        }

        return $query;
    }

    /**
     * Filter by callsign (partial match, case-insensitive).
     */
    public function forCallsign(Builder $query, ?string $callsign): Builder
    {
        if ($callsign === null || trim($callsign) === '') {
            return $query;
        }

        return $query->where('callsign', 'like', '%'.strtoupper(trim($callsign)).'%');
    }

    /**
     * Filter by one or more sections.
     *
     * @param  int[]  $sectionIds
     */
    public function forSection(Builder $query, array $sectionIds): Builder
    {
        if (empty($sectionIds)) {
            return $query;
        }

        return $query->whereIn('section_id', $sectionIds);
    }

    /**
     * Filter by duplicate status.
     *
     * @param  string|null  $duplicateFilter  'only' = duplicates only, 'exclude' = exclude duplicates, null = show all
     */
    public function forDuplicateStatus(Builder $query, ?string $duplicateFilter): Builder
    {
        if ($duplicateFilter === 'only') {
            return $query->where('is_duplicate', true);
        }

        if ($duplicateFilter === 'exclude') {
            return $query->where('is_duplicate', false);
        }

        return $query;
    }

    /**
     * Filter by transcription status.
     *
     * @param  string|null  $transcribedFilter  'only' = transcribed only, null = show all
     */
    public function forTranscribed(Builder $query, ?string $transcribedFilter): Builder
    {
        if ($transcribedFilter === 'only') {
            return $query->where('is_transcribed', true);
        }

        return $query;
    }

    /**
     * Filter by GOTA status.
     *
     * @param  string|null  $gotaFilter  'only' = GOTA only, 'exclude' = exclude GOTA, null = show all
     */
    public function forGotaStatus(Builder $query, ?string $gotaFilter): Builder
    {
        if ($gotaFilter === 'only') {
            return $query->where('is_gota_contact', true);
        }

        if ($gotaFilter === 'exclude') {
            return $query->where('is_gota_contact', false);
        }

        return $query;
    }

    /**
     * Apply chronological ordering (most recent first).
     */
    public function chronological(Builder $query): Builder
    {
        return $query->orderBy('qso_time', 'desc');
    }

    /**
     * Build a complete filtered query with all provided filters.
     *
     * @param  array{
     *     event_configuration_id: int,
     *     band_ids?: int[],
     *     mode_ids?: int[],
     *     station_ids?: int[],
     *     operator_ids?: int[],
     *     time_from?: ?string,
     *     time_to?: ?string,
     *     callsign?: ?string,
     *     section_ids?: int[],
     *     duplicate_filter?: ?string,
     *     transcribed_filter?: ?string,
     *     gota_filter?: ?string
     * }  $filters
     */
    public function applyFilters(array $filters): Builder
    {
        $query = $this->buildQuery();

        $query = $this->forEvent($query, $filters['event_configuration_id']);
        $query = $this->forBand($query, $filters['band_ids'] ?? []);
        $query = $this->forMode($query, $filters['mode_ids'] ?? []);
        $query = $this->forStation($query, $filters['station_ids'] ?? []);
        $query = $this->forOperator($query, $filters['operator_ids'] ?? []);
        $query = $this->forTimeRange($query, $filters['time_from'] ?? null, $filters['time_to'] ?? null);
        $query = $this->forCallsign($query, $filters['callsign'] ?? null);
        $query = $this->forSection($query, $filters['section_ids'] ?? []);
        $query = $this->forDuplicateStatus($query, $filters['duplicate_filter'] ?? null);
        $query = $this->forTranscribed($query, $filters['transcribed_filter'] ?? null);
        $query = $this->forGotaStatus($query, $filters['gota_filter'] ?? null);
        $query = $this->chronological($query);

        return $query;
    }
}
