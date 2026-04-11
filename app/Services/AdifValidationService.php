<?php

namespace App\Services;

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\OperatingClass;

class AdifValidationService
{
    /**
     * Validate staged records against event constraints.
     *
     * @return array{invalid_count: int, valid_count: int}
     */
    public function validate(AdifImport $import): array
    {
        $result = ['invalid_count' => 0, 'valid_count' => 0];

        $event = $import->eventConfiguration->event;
        $eventStart = $event->start_time;
        $eventEnd = $event->end_time;
        $eventTypeId = $event->event_type_id;

        $validClassCodes = OperatingClass::query()
            ->where('event_type_id', $eventTypeId)
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
            $errors = [];

            // Validate QSO time exists
            if ($record->qso_time === null) {
                $errors[] = 'Missing QSO time';
            } elseif ($eventStart && $eventEnd) {
                // Validate QSO time is within event window
                if ($record->qso_time->lt($eventStart) || $record->qso_time->gt($eventEnd)) {
                    $errors[] = "QSO time {$record->qso_time->format('Y-m-d H:i')} is outside event window ({$eventStart->format('Y-m-d H:i')} to {$eventEnd->format('Y-m-d H:i')})";
                }
            }

            // Validate class code against event type
            if ($record->exchange_class !== null) {
                // Extract letter portion from class like "3A" -> "A"
                if (preg_match('/(\d+)([A-Za-z]+)/', $record->exchange_class, $matches)) {
                    $classLetter = strtoupper($matches[2]);
                    if (! in_array($classLetter, $validClassCodes, true)) {
                        $errors[] = "Class code {$classLetter} is not valid for {$event->name}";
                    }
                }
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
}
