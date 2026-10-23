<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Jobs;

use App\Enums\NotificationCategory;
use App\Models\EventConfiguration;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class GenerateAlbumZip implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $eventConfigurationId,
        public int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->eventConfigurationId;
    }

    /**
     * Remember that a user wants this album. Only one ZIP job runs per event
     * configuration at a time, so everyone who asks while it is queued or
     * running is notified when that one ZIP is ready.
     */
    public static function addRequester(int $eventConfigurationId, int $userId): void
    {
        Cache::lock(self::requestersKey($eventConfigurationId).':lock', 5)->block(3, function () use ($eventConfigurationId, $userId) {
            $key = self::requestersKey($eventConfigurationId);
            $ids = Cache::get($key, []);
            $ids[] = $userId;

            Cache::put($key, array_values(array_unique($ids)), now()->addHour());
        });
    }

    public static function requestersKey(int $eventConfigurationId): string
    {
        return "album-zip-requesters:{$eventConfigurationId}";
    }

    /**
     * Everyone waiting on this ZIP, including the user the job was dispatched
     * for. Clears the waiting list.
     *
     * @return Collection<int, User>
     */
    protected function takeRequesters(): Collection
    {
        $key = self::requestersKey($this->eventConfigurationId);

        $ids = Cache::lock($key.':lock', 5)->block(3, fn () => tap(Cache::get($key, []), fn () => Cache::forget($key)));

        return User::whereIn('id', array_unique([...$ids, $this->userId]))->get();
    }

    protected function notifyRequesters(NotificationCategory $category, string $title, string $message, ?string $url = null): void
    {
        foreach ($this->takeRequesters() as $user) {
            $user->notify(new InAppNotification(
                category: $category,
                title: $title,
                message: $message,
                url: $url,
            ));
        }
    }

    public function handle(): void
    {
        $eventConfig = EventConfiguration::with('event')->findOrFail($this->eventConfigurationId);
        $images = $eventConfig->images()->get();

        if ($images->isEmpty()) {
            $this->notifyRequesters(
                NotificationCategory::Photos,
                'Album Export',
                'No photos to export for '.$eventConfig->event->name.'.',
            );

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

            $this->notifyRequesters(
                NotificationCategory::Photos,
                'Album Export Failed',
                'Failed to create the photo album for '.$eventConfig->event->name.'. Please try again.',
            );

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

        $this->notifyRequesters(
            NotificationCategory::Photos,
            'Album Ready',
            "Your photo album for {$eventConfig->event->name} is ready to download. The download link expires in 24 hours.",
            $downloadUrl,
        );
    }

    /**
     * Handle job failure — notify user and clean up partial zip.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Album export failed for event config {$this->eventConfigurationId}: {$exception->getMessage()}");

        $eventConfig = EventConfiguration::with('event')->find($this->eventConfigurationId);
        $eventName = $eventConfig?->event?->name ?? 'unknown event';

        // Clean up any partial zip files
        $exportDir = "exports/gallery/{$this->eventConfigurationId}";
        if (Storage::disk('local')->exists($exportDir)) {
            foreach (Storage::disk('local')->files($exportDir) as $file) {
                Storage::disk('local')->delete($file);
            }
        }

        $this->notifyRequesters(
            NotificationCategory::Photos,
            'Album Export Failed',
            "Failed to create the photo album for {$eventName}. Please try again.",
        );
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
