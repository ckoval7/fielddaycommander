<?php

namespace App\Jobs;

use App\Enums\NotificationCategory;
use App\Models\EventConfiguration;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class GenerateAlbumZip implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $eventConfigurationId,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $eventConfig = EventConfiguration::with('event')->findOrFail($this->eventConfigurationId);
        $user = User::findOrFail($this->userId);
        $images = $eventConfig->images()->get();

        if ($images->isEmpty()) {
            $user->notify(new InAppNotification(
                category: NotificationCategory::Photos,
                title: 'Album Export',
                message: 'No photos to export for '.$eventConfig->event->name.'.',
            ));

            return;
        }

        $exportDir = "exports/gallery/{$eventConfig->id}";
        $filename = 'album-'.time().'.zip';
        $relativePath = "{$exportDir}/{$filename}";
        $absolutePath = Storage::disk('local')->path($relativePath);

        Storage::disk('local')->makeDirectory($exportDir);

        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Log::error("Failed to create zip for event config {$eventConfig->id}");

            $user->notify(new InAppNotification(
                category: NotificationCategory::Photos,
                title: 'Album Export Failed',
                message: 'Failed to create the photo album for '.$eventConfig->event->name.'. Please try again.',
            ));

            return;
        }

        $usedNames = [];

        foreach ($images as $image) {
            $storagePath = $image->storage_path;

            if (! Storage::disk('local')->exists($storagePath)) {
                continue;
            }

            $zipName = $this->resolveFilename($image->filename, $usedNames);
            $usedNames[] = $zipName;

            $zip->addFile(Storage::disk('local')->path($storagePath), $zipName);
        }

        $zip->close();

        $downloadUrl = null;
        if (Route::has('album-export.download')) {
            $downloadUrl = route('album-export.download', [
                'eventConfiguration' => $eventConfig->id,
                'filename' => $filename,
            ]);
        }

        $user->notify(new InAppNotification(
            category: NotificationCategory::Photos,
            title: 'Album Ready',
            message: "Your photo album for {$eventConfig->event->name} is ready to download.",
            url: $downloadUrl,
        ));
    }

    /**
     * Handle job failure — notify user and clean up partial zip.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Album export failed for event config {$this->eventConfigurationId}: {$exception->getMessage()}");

        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $eventConfig = EventConfiguration::with('event')->find($this->eventConfigurationId);
        $eventName = $eventConfig?->event?->name ?? 'unknown event';

        // Clean up any partial zip files
        $exportDir = "exports/gallery/{$this->eventConfigurationId}";
        if (Storage::disk('local')->exists($exportDir)) {
            foreach (Storage::disk('local')->files($exportDir) as $file) {
                Storage::disk('local')->delete($file);
            }
        }

        $user->notify(new InAppNotification(
            category: NotificationCategory::Photos,
            title: 'Album Export Failed',
            message: "Failed to create the photo album for {$eventName}. Please try again.",
        ));
    }

    /**
     * Resolve duplicate filenames by appending a counter suffix.
     */
    protected function resolveFilename(string $filename, array $usedNames): string
    {
        if (! in_array($filename, $usedNames)) {
            return $filename;
        }

        $pathInfo = pathinfo($filename);
        $name = $pathInfo['filename'];
        $ext = isset($pathInfo['extension']) ? '.'.$pathInfo['extension'] : '';

        $counter = 2;
        while (in_array("{$name}-{$counter}{$ext}", $usedNames)) {
            $counter++;
        }

        return "{$name}-{$counter}{$ext}";
    }
}
