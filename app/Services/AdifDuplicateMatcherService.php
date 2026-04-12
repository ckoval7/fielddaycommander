<?php

namespace App\Services;

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Contact;

class AdifDuplicateMatcherService
{
    private const TIME_WINDOW_MINUTES = 10;

    /**
     * Fields on Contact that must be non-null for a match to be considered an exact duplicate.
     *
     * @var array<string>
     */
    private const REQUIRED_FIELDS = ['power_watts', 'exchange_class'];

    /**
     * Match staged records against existing contacts.
     *
     * @return array{new: int, merge: int, skip: int}
     */
    public function match(AdifImport $import): array
    {
        $summary = ['new' => 0, 'merge' => 0, 'skip' => 0];

        $records = $import->records()
            ->where('status', AdifRecordStatus::Mapped)
            ->get();

        foreach ($records as $record) {
            $matchedContact = $this->findTimeWindowMatch($record, $import->event_configuration_id);

            if ($matchedContact === null) {
                $record->status = AdifRecordStatus::Ready;
                $record->save();
                $summary['new']++;

                continue;
            }

            $record->matched_contact_id = $matchedContact->id;

            if ($this->isExactDuplicate($record, $matchedContact)) {
                $record->status = AdifRecordStatus::Skipped;
                $summary['skip']++;
            } else {
                $record->status = AdifRecordStatus::DuplicateMatch;
                $summary['merge']++;
            }

            $record->save();
        }

        return $summary;
    }

    private function findTimeWindowMatch(AdifImportRecord $record, int $eventConfigId): ?Contact
    {
        if ($record->qso_time === null || $record->callsign === null) {
            return null;
        }

        return Contact::query()
            ->where('event_configuration_id', $eventConfigId)
            ->where('callsign', strtoupper($record->callsign))
            ->where('band_id', $record->band_id)
            ->where('mode_id', $record->mode_id)
            ->whereBetween('qso_time', [
                $record->qso_time->copy()->subMinutes(self::TIME_WINDOW_MINUTES),
                $record->qso_time->copy()->addMinutes(self::TIME_WINDOW_MINUTES),
            ])
            ->first();
    }

    /**
     * An exact duplicate has all required fields populated on the existing contact
     * and the record carries a matching section that confirms no data is missing.
     */
    private function isExactDuplicate(AdifImportRecord $record, Contact $contact): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if ($contact->{$field} === null) {
                return false;
            }
        }

        return $record->section_id !== null && $record->section_id === $contact->section_id;
    }
}
