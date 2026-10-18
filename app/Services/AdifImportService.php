<?php

namespace App\Services;

use App\Enums\AdifImportStatus;
use App\Enums\AdifRecordStatus;
use App\Exceptions\AdifImportRecordException;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Scoring\DomainEvents\QsoLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdifImportService
{
    /**
     * Fields on Contact that can be filled by merge (only if currently null).
     *
     * @var array<string>
     */
    private const MERGEABLE_FIELDS = ['power_watts', 'notes', 'exchange_class'];

    public function __construct(
        private readonly DuplicateCheckService $dupes = new DuplicateCheckService,
    ) {}

    /**
     * Records written per locked batch. Each batch holds the event lock only
     * while it inserts and settles, so live logging (including UDP loggers,
     * which give up on a long lock wait) is never blocked for a whole file.
     */
    private const BATCH_SIZE = 100;

    /**
     * Sessions the import created this run, keyed by station, operator, band
     * and mode, with the QSO time span they cover.
     *
     * @var array<string, array{session: OperatingSession, owned: bool}>
     */
    private array $sessions = [];

    /** @var array<int, Station|null> */
    private array $stations = [];

    /**
     * Mark the import as running and record how much work it has, so the
     * wizard can show progress before a queued import starts. Safe to call
     * more than once.
     */
    public function begin(AdifImport $import): void
    {
        if ($import->status === AdifImportStatus::Importing) {
            return;
        }

        $import->update([
            'status' => AdifImportStatus::Importing,
            'progress_processed' => 0,
            'progress_total' => $import->records()
                ->whereIn('status', [AdifRecordStatus::Ready, AdifRecordStatus::DuplicateMatch])
                ->count(),
        ]);
    }

    /**
     * Execute the final import from staging into contacts.
     *
     * Records are written in batches, each under the event-config lock: new
     * contacts are inserted at 0 points, then each dupe group they touched is
     * settled by DuplicateCheckService against everything already stored
     * (earlier batches included): the earliest in-period contact is the
     * original, later ones are dupes, and out-of-period contacts earn nothing.
     * Each record is marked imported (pointing at its contact) or merged in
     * the same transaction as its batch, so an import that stops part way
     * can be resumed without writing anything twice.
     *
     * With a time budget, the import returns false once the budget is spent
     * and more records remain; call it again (e.g. from a fresh queued job)
     * to continue. It returns true once it has finished, successfully or not.
     *
     * Operating sessions the import creates are closed from the start and
     * span exactly the QSOs imported into them, so an import never leaves a
     * station looking occupied.
     *
     * Contacts are written without model events; bonuses are reconciled once,
     * after the last batch commits and the lock is released.
     *
     * Unmapped records fail the import before anything is written. A batch
     * that fails for another reason (a database error) rolls back on its
     * own; batches already committed stay imported and are marked so.
     */
    public function import(AdifImport $import, ?int $timeBudgetSeconds = null): bool
    {
        $deadline = $timeBudgetSeconds !== null ? microtime(true) + $timeBudgetSeconds : null;
        $this->begin($import);
        $this->sessions = [];
        $this->stations = [];
        $failed = false;

        try {
            $this->assertRecordsMapped($import);
            $this->loadImportSessions($import);

            while (true) {
                // The batch is read inside the lock, so two runs of the same
                // import (a retried queued job) can never take the same records.
                $written = $this->dupes->locked($import->event_configuration_id, function () use ($import): int {
                    $batch = $import->records()
                        ->whereIn('status', [AdifRecordStatus::Ready, AdifRecordStatus::DuplicateMatch])
                        ->orderBy('id')
                        ->limit(self::BATCH_SIZE)
                        ->get();

                    if ($batch->isNotEmpty()) {
                        $this->importBatch($batch, $import);
                    }

                    return $batch->count();
                });

                if ($written === 0) {
                    break;
                }

                $import->increment('progress_processed', $written);

                if ($deadline !== null && microtime(true) >= $deadline) {
                    return false;
                }
            }
        } catch (\Throwable $exception) {
            $failed = true;

            Log::error('ADIF import failed', [
                'adif_import_id' => $import->id,
                'event_configuration_id' => $import->event_configuration_id,
                'user_id' => $import->user_id,
                'exception' => $exception,
            ]);
        }

        $this->finish($import, $failed);

        return true;
    }

    /**
     * Finish an import: note dupes and out-of-period contacts on their
     * records, reconcile bonuses once, and store the final counts.
     */
    public function finish(AdifImport $import, bool $failed): void
    {
        // Noted once every batch is settled: a later batch can turn an
        // earlier batch's contact into a dupe.
        $this->noteSettledRecords($import);

        $lastContactId = $import->records()
            ->where('status', AdifRecordStatus::Imported)
            ->whereNotNull('contact_id')
            ->max('contact_id');
        $lastContact = $lastContactId !== null ? Contact::find($lastContactId) : null;

        if ($lastContact !== null) {
            event(new QsoLogged($lastContact, $import->event_configuration_id));
        }

        $import->update([
            'status' => $failed ? AdifImportStatus::Failed : AdifImportStatus::Completed,
            'imported_records' => $import->records()->where('status', AdifRecordStatus::Imported)->count(),
            'merged_records' => $import->records()->where('status', AdifRecordStatus::Merged)->count(),
            'skipped_records' => $import->records()->where('status', AdifRecordStatus::Skipped)->count(),
        ]);
    }

    /**
     * Fail before any batch is written when a ready record lacks the station
     * or operator mapping it needs, so a bad file is not half imported.
     */
    private function assertRecordsMapped(AdifImport $import): void
    {
        $unmappedId = $import->records()
            ->where('status', AdifRecordStatus::Ready)
            ->where(fn ($query) => $query->whereNull('station_id')->orWhereNull('operator_user_id'))
            ->value('id');

        if ($unmappedId !== null) {
            throw new AdifImportRecordException("Record {$unmappedId} missing station or operator mapping");
        }
    }

    /**
     * Pick up the sessions an earlier run of this import created, so a
     * resumed import keeps filling them instead of opening new ones.
     */
    private function loadImportSessions(AdifImport $import): void
    {
        $sessionIds = Contact::query()
            ->whereIn('id', $import->records()->whereNotNull('contact_id')->select('contact_id'))
            ->distinct()
            ->pluck('operating_session_id');

        foreach (OperatingSession::query()->whereKey($sessionIds)->whereNotNull('end_time')->get() as $session) {
            $this->sessions[$this->sessionKey($session->station_id, $session->operator_user_id, $session->band_id, $session->mode_id)] = [
                'session' => $session,
                'owned' => true,
            ];
        }
    }

    /**
     * Write one batch of records and settle the dupe groups it touched.
     * Runs inside the event lock.
     *
     * @param  Collection<int, AdifImportRecord>  $batch
     */
    private function importBatch(Collection $batch, AdifImport $import): void
    {
        /** @var array<int, Contact> $created keyed by import record id */
        $created = [];
        /** @var array<int, int> $sessionCounts qso_count increments keyed by session id */
        $sessionCounts = [];
        /** @var array<int, array{0: Carbon, 1: Carbon}> $sessionSpans QSO time span per owned session id */
        $sessionSpans = [];

        foreach ($batch as $record) {
            match ($record->status) {
                AdifRecordStatus::Ready => $created[$record->id] = $this->createContact($record, $import, $sessionCounts, $sessionSpans),
                AdifRecordStatus::DuplicateMatch => $this->mergeContact($record),
                default => null,
            };
        }

        foreach ($sessionCounts as $sessionId => $count) {
            OperatingSession::query()->whereKey($sessionId)->increment('qso_count', $count);
        }

        $this->extendOwnedSessions($sessionSpans);
        $this->markImported($created);

        $this->dupes->settleGroups(
            array_map(fn (Contact $contact) => $this->dupes->keyFor($contact), $created),
            quietly: true,
        );
    }

    /**
     * Insert a contact for a ready record, unpriced: settleGroups() sets its
     * duplicate status and points once its batch is in.
     *
     * Inserted quietly so a bulk import neither reconciles bonuses nor sends
     * new-section notifications per row; import() reconciles once at the end.
     *
     * @param  array<int, int>  $sessionCounts
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $sessionSpans
     */
    private function createContact(AdifImportRecord $record, AdifImport $import, array &$sessionCounts, array &$sessionSpans): Contact
    {
        if ($record->station_id === null || $record->operator_user_id === null) {
            throw new AdifImportRecordException("Record {$record->id} missing station or operator mapping");
        }

        ['session' => $session, 'owned' => $owned] = $this->sessionFor($record);
        $station = $this->stations[$record->station_id] ??= Station::find($record->station_id);

        $contact = new Contact([
            'uuid' => Str::uuid()->toString(),
            'event_configuration_id' => $import->event_configuration_id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $record->operator_user_id,
            'band_id' => $record->band_id,
            'mode_id' => $record->mode_id,
            'submode' => $record->raw_data['SUBMODE'] ?? $record->mode_name,
            'qso_time' => $record->qso_time,
            'callsign' => $record->callsign,
            'section_id' => $record->section_id,
            'exchange_class' => $record->exchange_class ? strtoupper($record->exchange_class) : null,
            'power_watts' => $session->power_watts,
            'points' => 0,
            'is_duplicate' => false,
            'duplicate_of_contact_id' => null,
            'is_gota_contact' => (bool) $station?->is_gota,
            'is_imported' => true,
        ]);
        $contact->saveQuietly();

        $sessionCounts[$session->id] = ($sessionCounts[$session->id] ?? 0) + 1;

        if ($owned && $record->qso_time !== null) {
            [$earliest, $latest] = $sessionSpans[$session->id] ?? [$record->qso_time, $record->qso_time];
            $sessionSpans[$session->id] = [$earliest->min($record->qso_time), $latest->max($record->qso_time)];
        }

        return $contact;
    }

    /**
     * The session a record's contact goes into: one this import already
     * created for the same station, operator, band and mode; else an open
     * session someone is operating in (left open, as it is theirs); else a
     * new session owned by the import, created already closed.
     *
     * @return array{session: OperatingSession, owned: bool}
     */
    private function sessionFor(AdifImportRecord $record): array
    {
        $key = $this->sessionKey($record->station_id, $record->operator_user_id, $record->band_id, $record->mode_id);

        if (isset($this->sessions[$key])) {
            return $this->sessions[$key];
        }

        $open = OperatingSession::query()
            ->where('station_id', $record->station_id)
            ->where('operator_user_id', $record->operator_user_id)
            ->where('band_id', $record->band_id)
            ->where('mode_id', $record->mode_id)
            ->where('is_transcription', false)
            ->whereNull('end_time')
            ->first();

        if ($open !== null) {
            return $this->sessions[$key] = ['session' => $open, 'owned' => false];
        }

        $qsoTime = $record->qso_time ?? now();

        $session = OperatingSession::create([
            'station_id' => $record->station_id,
            'operator_user_id' => $record->operator_user_id,
            'band_id' => $record->band_id,
            'mode_id' => $record->mode_id,
            'is_transcription' => false,
            'start_time' => $qsoTime,
            'end_time' => $qsoTime,
            'power_watts' => 100,
            'qso_count' => 0,
        ]);

        return $this->sessions[$key] = ['session' => $session, 'owned' => true];
    }

    private function sessionKey(?int $stationId, ?int $operatorUserId, ?int $bandId, ?int $modeId): string
    {
        return implode('|', [$stationId, $operatorUserId, $bandId, $modeId]);
    }

    /**
     * Widen each session the import owns to cover the QSOs just written to it:
     * it starts at its first imported QSO and ends at its last.
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $sessionSpans
     */
    private function extendOwnedSessions(array $sessionSpans): void
    {
        foreach ($this->sessions as $entry) {
            $session = $entry['session'];

            if (! $entry['owned'] || ! isset($sessionSpans[$session->id])) {
                continue;
            }

            [$earliest, $latest] = $sessionSpans[$session->id];

            $session->start_time = $session->start_time === null ? $earliest : $session->start_time->min($earliest);
            $session->end_time = $session->end_time === null ? $latest : $session->end_time->max($latest);

            if ($session->isDirty()) {
                $session->save();
            }
        }
    }

    /**
     * Mark a batch's ready records imported, each pointing at its contact,
     * with one UPDATE.
     *
     * @param  array<int, Contact>  $created  keyed by import record id
     */
    private function markImported(array $created): void
    {
        if ($created === []) {
            return;
        }

        $cases = '';
        $bindings = [AdifRecordStatus::Imported->value];

        foreach ($created as $recordId => $contact) {
            $cases .= ' WHEN ? THEN ?';
            array_push($bindings, $recordId, $contact->id);
        }

        $ids = array_keys($created);
        $bindings[] = now();
        array_push($bindings, ...$ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        DB::update(
            "UPDATE adif_import_records SET status = ?, contact_id = CASE id{$cases} END, updated_at = ? WHERE id IN ({$placeholders})",
            $bindings,
        );
    }

    /**
     * Note on each imported record when its contact was settled as a
     * duplicate or fell outside the event period, so the per-record status
     * explains a contact that earned no points.
     */
    private function noteSettledRecords(AdifImport $import): void
    {
        $event = $import->eventConfiguration?->event;

        $import->records()
            ->where('status', AdifRecordStatus::Imported)
            ->whereNotNull('contact_id')
            ->select(['id', 'contact_id', 'notes'])
            ->chunkById(1000, function (Collection $records) use ($event) {
                $contacts = Contact::query()
                    ->whereKey($records->pluck('contact_id')->all())
                    ->get(['id', 'qso_time', 'is_duplicate', 'duplicate_of_contact_id'])
                    ->keyBy('id');

                foreach ($records as $record) {
                    $contact = $contacts->get($record->contact_id);
                    $notes = null;

                    if ($contact?->is_duplicate) {
                        $notes = "Imported as a duplicate of contact #{$contact->duplicate_of_contact_id} (0 points)";
                    } elseif ($contact?->qso_time !== null && $event?->start_time !== null && $event->end_time !== null
                        && ! $contact->qso_time->between($event->start_time, $event->end_time)) {
                        $notes = 'Imported outside the event period (0 points)';
                    }

                    if ($record->notes !== $notes) {
                        AdifImportRecord::query()->whereKey($record->id)->update(['notes' => $notes]);
                    }
                }
            });
    }

    private function mergeContact(AdifImportRecord $record): void
    {
        $contact = Contact::find($record->matched_contact_id);
        if ($contact === null) {
            $record->update(['status' => AdifRecordStatus::Merged]);

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

        // Quiet like the inserts: import() reconciles bonuses once at the end.
        if (! empty($updates)) {
            $contact->updateQuietly($updates);
        }

        $record->update(['status' => AdifRecordStatus::Merged]);
    }
}
