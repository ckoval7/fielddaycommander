<?php

namespace App\Services;

use App\Enums\AdifImportStatus;
use App\Enums\AdifRecordStatus;
use App\Exceptions\AdifImportRecordException;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdifImportService
{
    /**
     * Fields on Contact that can be filled by merge (only if currently null).
     *
     * @var array<string>
     */
    private const MERGEABLE_FIELDS = ['power_watts', 'notes', 'exchange_class'];

    public function __construct(private readonly SessionResolverService $sessionResolver) {}

    /**
     * Execute the final import from staging into contacts.
     */
    public function import(AdifImport $import): void
    {
        $import->update(['status' => AdifImportStatus::Importing]);

        $counters = ['imported' => 0, 'merged' => 0, 'skipped' => 0];

        try {
            DB::transaction(function () use ($import, &$counters) {
                $records = $import->records()->get();

                foreach ($records as $record) {
                    match ($record->status) {
                        AdifRecordStatus::Ready => $this->createContact($record, $import, $counters),
                        AdifRecordStatus::DuplicateMatch => $this->mergeContact($record, $counters),
                        AdifRecordStatus::Skipped => $counters['skipped']++,
                        default => null,
                    };
                }
            });

            $import->update([
                'status' => AdifImportStatus::Completed,
                'imported_records' => $counters['imported'],
                'merged_records' => $counters['merged'],
                'skipped_records' => $counters['skipped'],
            ]);
        } catch (\Throwable) {
            $import->update(['status' => AdifImportStatus::Failed]);
        }
    }

    /**
     * @param  array{imported: int, merged: int, skipped: int}  $counters
     */
    private function createContact(AdifImportRecord $record, AdifImport $import, array &$counters): void
    {
        if ($record->station_id === null || $record->operator_user_id === null) {
            throw new AdifImportRecordException("Record {$record->id} missing station or operator mapping");
        }

        $session = $this->findOrCreateSession($record);

        $dupeService = app(DuplicateCheckService::class);
        $dupeCheck = $dupeService->check(
            $record->callsign,
            $record->band_id,
            $record->mode_id,
            $import->event_configuration_id,
        );

        $mode = $record->mode;

        if ($dupeCheck['is_duplicate']) {
            $points = 0;
        } else {
            $eventConfiguration = EventConfiguration::find($import->event_configuration_id);
            $station = Station::find($record->station_id);

            $points = $eventConfiguration && $station && $mode
                ? $eventConfiguration->pointsForContact($mode, $station)
                : ($mode?->points_fd ?? 1);
        }

        Contact::create([
            'uuid' => Str::uuid()->toString(),
            'event_configuration_id' => $import->event_configuration_id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $record->operator_user_id,
            'band_id' => $record->band_id,
            'mode_id' => $record->mode_id,
            'qso_time' => $record->qso_time,
            'callsign' => $record->callsign,
            'section_id' => $record->section_id,
            'exchange_class' => $record->exchange_class ? strtoupper($record->exchange_class) : null,
            'power_watts' => $session->power_watts,
            'points' => $points,
            'is_duplicate' => $dupeCheck['is_duplicate'],
            'duplicate_of_contact_id' => $dupeCheck['duplicate_of_contact_id'],
            'is_imported' => true,
        ]);

        $session->increment('qso_count');
        $record->update(['status' => AdifRecordStatus::Imported]);
        $counters['imported']++;
    }

    /**
     * @param  array{imported: int, merged: int, skipped: int}  $counters
     */
    private function mergeContact(AdifImportRecord $record, array &$counters): void
    {
        $contact = Contact::find($record->matched_contact_id);
        if ($contact === null) {
            return;
        }

        $updates = [];

        foreach (self::MERGEABLE_FIELDS as $field) {
            if ($contact->{$field} === null) {
                $value = match ($field) {
                    'exchange_class' => $record->exchange_class ? strtoupper($record->exchange_class) : null,
                    default => null,
                };

                if ($value !== null) {
                    $updates[$field] = $value;
                }
            }
        }

        if (! empty($updates)) {
            $contact->update($updates);
        }

        $record->update(['status' => AdifRecordStatus::Merged]);
        $counters['merged']++;
    }

    private function findOrCreateSession(AdifImportRecord $record): OperatingSession
    {
        return $this->sessionResolver->resolve(
            stationId: $record->station_id,
            operatorUserId: $record->operator_user_id,
            bandId: $record->band_id,
            modeId: $record->mode_id,
            startTime: $record->qso_time,
        );
    }
}
