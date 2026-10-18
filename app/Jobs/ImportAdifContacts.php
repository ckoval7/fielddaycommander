<?php

namespace App\Jobs;

use App\Enums\AdifImportStatus;
use App\Models\AdifImport;
use App\Models\AuditLog;
use App\Services\AdifImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Import an ADIF file's staged records into the log off the request cycle.
 *
 * Each run works for a bounded time and then queues a fresh run for the
 * rest, so no single job outlives the queue's retry_after (a job held past
 * it is handed to another worker). The import is resumable and takes the
 * event lock per batch, so a retried or overlapping run writes nothing twice.
 */
class ImportAdifContacts implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds of import work per run, well inside the default 90 s retry_after.
     */
    public const TIME_BUDGET_SECONDS = 40;

    public int $tries = 3;

    public int $timeout = 85;

    public function __construct(public int $adifImportId) {}

    public function handle(AdifImportService $service): void
    {
        $import = AdifImport::find($this->adifImportId);

        if ($import === null || in_array($import->status, [AdifImportStatus::Completed, AdifImportStatus::Failed], true)) {
            return;
        }

        try {
            $finished = $service->import($import, self::TIME_BUDGET_SECONDS);
        } catch (Throwable $exception) {
            Log::error('ADIF import failed', [
                'adif_import_id' => $import->id,
                'event_configuration_id' => $import->event_configuration_id,
                'user_id' => $import->user_id,
                'exception' => $exception,
            ]);

            $import->update(['status' => AdifImportStatus::Failed]);
            $this->audit($import->refresh());

            return;
        }

        if ($finished === false) {
            self::dispatch($this->adifImportId);

            return;
        }

        $this->audit($import->refresh());
    }

    /**
     * The worker gave up on the job (timeout or repeated crash): fail the
     * import so the wizard stops waiting. Sessions the import created are
     * already closed, and committed batches stay imported.
     */
    public function failed(?Throwable $exception): void
    {
        $import = AdifImport::find($this->adifImportId);

        if ($import === null || $import->status !== AdifImportStatus::Importing) {
            return;
        }

        Log::error('ADIF import failed', [
            'adif_import_id' => $import->id,
            'event_configuration_id' => $import->event_configuration_id,
            'user_id' => $import->user_id,
            'exception' => $exception,
        ]);

        $import->update(['status' => AdifImportStatus::Failed]);
        $this->audit($import);
    }

    private function audit(AdifImport $import): void
    {
        if ($import->status === AdifImportStatus::Completed) {
            AuditLog::log('adif.import.completed', userId: $import->user_id, auditable: $import, newValues: [
                'imported_records' => $import->imported_records,
                'merged_records' => $import->merged_records,
                'skipped_records' => $import->skipped_records,
            ]);
        } elseif ($import->status === AdifImportStatus::Failed) {
            AuditLog::log('adif.import.failed', userId: $import->user_id, auditable: $import);
        }
    }
}
