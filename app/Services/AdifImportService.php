<?php

namespace App\Services;

use App\Enums\AdifImportStatus;
use App\Enums\AdifRecordStatus;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Contact;
use App\Models\OperatingSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdifImportService
{
    /**
     * Fields on Contact that can be filled by merge (only if currently null).
     *
     * @var array<string>
     */
    private const MERGEABLE_FIELDS = ['power_watts', 'notes', 'received_exchange'];

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
            throw new \RuntimeException("Record {$record->id} missing station or operator mapping");
        }

        $session = $this->findOrCreateSession($record);

        $exchange = $this->buildReceivedExchange($record);

        $dupeService = app(DuplicateCheckService::class);
        $dupeCheck = $dupeService->check(
            $record->callsign,
            $record->band_id,
            $record->mode_id,
            $import->event_configuration_id,
        );

        $mode = $record->mode;
        $points = $dupeCheck['is_duplicate'] ? 0 : ($mode?->points_fd ?? 1);

        Contact::create([
            'uuid' => Str::uuid()->toString(),
            'event_configuration_id' => $import->event_configuration_id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $import->user_id,
            'band_id' => $record->band_id,
            'mode_id' => $record->mode_id,
            'qso_time' => $record->qso_time,
            'callsign' => $record->callsign,
            'section_id' => $record->section_id,
            'received_exchange' => $exchange,
            'power_watts' => $session->power_watts,
            'points' => $points,
            'is_duplicate' => $dupeCheck['is_duplicate'],
            'duplicate_of_contact_id' => $dupeCheck['duplicate_of_contact_id'],
            'is_transcribed' => true,
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

        $exchange = $this->buildReceivedExchange($record);
        $updates = [];

        foreach (self::MERGEABLE_FIELDS as $field) {
            if ($contact->{$field} === null) {
                $value = match ($field) {
                    'received_exchange' => $exchange,
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
        return OperatingSession::firstOrCreate([
            'station_id' => $record->station_id,
            'operator_user_id' => $record->operator_user_id,
            'band_id' => $record->band_id,
            'mode_id' => $record->mode_id,
            'is_transcription' => true,
        ], [
            'start_time' => $record->qso_time,
            'power_watts' => 100,
            'qso_count' => 0,
        ]);
    }

    private function buildReceivedExchange(AdifImportRecord $record): string
    {
        return strtoupper(trim(
            ($record->callsign ?? '').' '.
            ($record->exchange_class ?? '').' '.
            ($record->section_code ?? '')
        ));
    }
}
