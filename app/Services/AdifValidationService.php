<?php

namespace App\Services;

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\OperatingClass;
use Illuminate\Support\Carbon;

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

        foreach ($records as $record) {
            $errors = $this->validateRecord($record, $event->start_time, $event->end_time, $validClassCodes, $event->name);

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
