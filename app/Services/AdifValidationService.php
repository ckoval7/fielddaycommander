<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Band;
use App\Models\OperatingClass;
use App\Models\Station;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AdifValidationService
{
    private const QSO_TIME_FORMAT = 'Y-m-d H:i';

    /**
     * Validate staged records against event constraints.
     *
     * @return array{invalid_count: int, valid_count: int}
     */
    public function validate(AdifImport $import): array
    {
        $result = ['invalid_count' => 0, 'valid_count' => 0];

        $event = $import->eventConfiguration->event;

        $validClassCodes = OperatingClass::query()
            ->where('event_type_id', $event->event_type_id)
            ->pluck('code')
            ->map(fn ($code) => strtoupper($code))
            ->toArray();

        $records = $import->records()
            ->whereIn('status', [
                AdifRecordStatus::Mapped,
                AdifRecordStatus::Ready,
                AdifRecordStatus::DuplicateMatch,
            ])
            ->get();

        $vhfOnlyStations = Station::query()
            ->whereKey($records->pluck('station_id')->filter()->unique())
            ->where('is_vhf_only', true)
            ->get()
            ->keyBy('id');
        $bands = $vhfOnlyStations->isEmpty()
            ? collect()
            : Band::query()->whereKey($records->pluck('band_id')->filter()->unique())->get()->keyBy('id');

        foreach ($records as $record) {
            $errors = $this->validateRecord($record, $event->start_time, $event->end_time, $validClassCodes, $event->name);

            $bandError = $this->bandRestrictionError($record, $vhfOnlyStations, $bands);
            if ($bandError !== null) {
                $errors[] = $bandError;
            }

            if (! empty($errors)) {
                $record->update([
                    'status' => AdifRecordStatus::Invalid,
                    'notes' => implode('; ', $errors),
                ]);
                $result['invalid_count']++;
            } else {
                $result['valid_count']++;
            }
        }

        return $result;
    }

    /**
     * Why the record's station can't log on its band (the free VHF station
     * operates only on 6m and up), or null when it can.
     *
     * @param  Collection<int, Station>  $vhfOnlyStations
     * @param  Collection<int, Band>  $bands
     */
    private function bandRestrictionError(AdifImportRecord $record, Collection $vhfOnlyStations, Collection $bands): ?string
    {
        $station = $vhfOnlyStations->get($record->station_id);
        $band = $bands->get($record->band_id);

        if ($station === null || $band === null) {
            return null;
        }

        $message = $station->bandRestrictionMessage($band);

        // Record notes join their errors with "; ", so drop the closing period.
        return $message === null ? null : rtrim($message, '.');
    }

    /**
     * Validate a single record against event constraints.
     *
     * @param  array<string>  $validClassCodes
     * @return array<string>
     */
    private function validateRecord(
        AdifImportRecord $record,
        ?Carbon $eventStart,
        ?Carbon $eventEnd,
        array $validClassCodes,
        string $eventName,
    ): array {
        $errors = [];

        if ($record->qso_time === null) {
            $errors[] = 'Missing QSO time';
        } elseif ($eventStart && $eventEnd
            && ($record->qso_time->lt($eventStart) || $record->qso_time->gt($eventEnd))) {
            $errors[] = "QSO time {$record->qso_time->format(self::QSO_TIME_FORMAT)} is outside event window ({$eventStart->format(self::QSO_TIME_FORMAT)} to {$eventEnd->format(self::QSO_TIME_FORMAT)})";
        }

        if ($record->exchange_class !== null
            && preg_match('/(\d+)([A-Za-z]+)/', $record->exchange_class, $matches)) {
            $classLetter = strtoupper($matches[2]);
            if (! in_array($classLetter, $validClassCodes, true)) {
                $errors[] = "Class code {$classLetter} is not valid for {$eventName}";
            }
        }

        return $errors;
    }
}
