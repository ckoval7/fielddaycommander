<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAlbumZip;
use App\Models\AuditLog;
use App\Models\EventConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AlbumExportController extends Controller
{
    public function store(EventConfiguration $eventConfiguration): RedirectResponse
    {
        GenerateAlbumZip::dispatch($eventConfiguration->id, auth()->id());

        AuditLog::log(
            action: 'album.export.requested',
            auditable: $eventConfiguration,
            newValues: [
                'event' => $eventConfiguration->event->name,
            ]
        );

        return back()->with('status', 'Your album is being prepared. You\'ll receive a notification when it\'s ready.');
    }

    public function download(EventConfiguration $eventConfiguration, string $filename): StreamedResponse
    {
        // Validate filename format to prevent path traversal
        if (! preg_match('/^album-\d+\.zip$/', $filename)) {
            abort(404);
        }

        $path = "exports/gallery/{$eventConfiguration->id}/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        // Check if file is expired (older than 24 hours)
        $lastModified = Storage::disk('local')->lastModified($path);
        if (time() - $lastModified > 86400) {
            Storage::disk('local')->delete($path);
            abort(404);
        }

        AuditLog::log(
            action: 'album.export.downloaded',
            auditable: $eventConfiguration,
            newValues: [
                'event' => $eventConfiguration->event->name,
                'filename' => $filename,
            ]
        );

        $eventName = $eventConfiguration->event->name;
        $downloadName = str($eventName)->slug().'-photos.zip';

        return Storage::disk('local')->download($path, $downloadName, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
