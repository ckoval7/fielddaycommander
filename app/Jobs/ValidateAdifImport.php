<?php

namespace App\Jobs;

use App\Enums\AdifImportStatus;
use App\Models\AdifImport;
use App\Services\AdifDuplicateMatcherService;
use App\Services\AdifValidationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Match an ADIF file's mapped records against the log and validate them
 * against the event, off the request cycle. The wizard polls the import's
 * status and progress until it reaches PendingReview.
 *
 * Both steps only move records forward (Mapped -> Ready/DuplicateMatch/
 * Skipped -> Invalid), so a retried run picks up where the last one stopped.
 */
class ValidateAdifImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 85;

    public function __construct(public int $adifImportId) {}

    public function handle(AdifDuplicateMatcherService $matcher, AdifValidationService $validator): void
    {
        $import = AdifImport::find($this->adifImportId);

        if ($import === null || $import->status !== AdifImportStatus::Validating) {
            return;
        }

        $import->update(['progress_processed' => 0, 'progress_total' => 2]);

        try {
            $matcher->match($import);
            $import->update(['progress_processed' => 1]);

            $validator->validate($import);
        } catch (Throwable $exception) {
            Log::error('ADIF import validation failed', [
                'adif_import_id' => $import->id,
                'event_configuration_id' => $import->event_configuration_id,
                'user_id' => $import->user_id,
                'exception' => $exception,
            ]);

            $import->update(['status' => AdifImportStatus::Failed]);

            return;
        }

        $import->update(['status' => AdifImportStatus::PendingReview, 'progress_processed' => 2]);
    }

    public function failed(?Throwable $exception): void
    {
        AdifImport::query()
            ->whereKey($this->adifImportId)
            ->where('status', AdifImportStatus::Validating)
            ->update(['status' => AdifImportStatus::Failed]);
    }
}
