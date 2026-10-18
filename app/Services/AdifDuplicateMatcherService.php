<?php

namespace App\Services;

use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Contact;
use Illuminate\Support\Collection;

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

        $candidates = $this->candidateContacts($records, $import->event_configuration_id);

        /** @var array<int, int> $readyIds */
        $readyIds = [];

        foreach ($records as $record) {
            $matchedContact = $this->findTimeWindowMatch($record, $candidates);

            if ($matchedContact === null) {
                $readyIds[] = $record->id;
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

        foreach (array_chunk($readyIds, 1000) as $ids) {
            AdifImportRecord::query()->whereKey($ids)->update(['status' => AdifRecordStatus::Ready->value]);
        }

        return $summary;
    }

    /**
     * Existing contacts that could match any of the records, loaded once and
     * grouped by callsign, band and mode (oldest first), instead of one
     * query per record.
     *
     * @param  Collection<int, AdifImportRecord>  $records
     * @return array<string, array<int, Contact>>
     */
    private function candidateContacts(Collection $records, int $eventConfigId): array
    {
        $callsigns = $records->pluck('callsign')->filter()->map(fn (string $callsign) => strtoupper($callsign))->unique()->values()->all();
        $candidates = [];

        foreach (array_chunk($callsigns, 500) as $chunk) {
            $contacts = Contact::query()
                ->where('event_configuration_id', $eventConfigId)
                ->whereIn('callsign', $chunk)
                ->orderBy('id')
                ->get();

            foreach ($contacts as $contact) {
                $candidates[$this->candidateKey($contact->callsign, $contact->band_id, $contact->mode_id)][] = $contact;
            }
        }

        return $candidates;
    }

    private function candidateKey(?string $callsign, ?int $bandId, ?int $modeId): string
    {
        return strtoupper((string) $callsign).'|'.$bandId.'|'.$modeId;
    }

    /**
     * @param  array<string, array<int, Contact>>  $candidates
     */
    private function findTimeWindowMatch(AdifImportRecord $record, array $candidates): ?Contact
    {
        if ($record->qso_time === null || $record->callsign === null) {
            return null;
        }

        $from = $record->qso_time->copy()->subMinutes(self::TIME_WINDOW_MINUTES);
        $to = $record->qso_time->copy()->addMinutes(self::TIME_WINDOW_MINUTES);

        foreach ($candidates[$this->candidateKey($record->callsign, $record->band_id, $record->mode_id)] ?? [] as $contact) {
            if ($contact->qso_time !== null && $contact->qso_time->between($from, $to)) {
                return $contact;
            }
        }

        return null;
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
