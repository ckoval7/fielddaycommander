<?php

namespace App\Observers;

use App\Enums\NotificationCategory;
use App\Models\OperatingSession;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class OperatingSessionObserver
{
    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Handle the OperatingSession "created" event.
     */
    public function created(OperatingSession $session): void
    {
        if ($session->is_transcription) {
            return;
        }

        try {
            $operator = $session->operator;
            $station = $session->station;
            $operatorName = $operator?->call_sign ?? 'Someone';
            $stationName = $station?->name ?? 'a station';

            $this->notificationService->notifyAll(
                category: NotificationCategory::StationStatus,
                title: 'Station Occupied',
                message: "{$operatorName} started operating at {$stationName}",
                url: '/stations',
                groupKey: "station_status_{$station?->id}",
            );
        } catch (\Exception $e) {
            Log::error('Failed to send operating session created notification', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the OperatingSession "updated" event.
     */
    public function updated(OperatingSession $session): void
    {
        if ($session->is_transcription) {
            return;
        }

        if (! $session->wasChanged('end_time') || $session->end_time === null) {
            return;
        }

        try {
            $station = $session->station;
            $stationName = $station?->name ?? 'A station';

            $this->notificationService->notifyAll(
                category: NotificationCategory::StationStatus,
                title: 'Station Available',
                message: "{$stationName} is now available",
                url: '/stations',
                groupKey: "station_status_{$station?->id}",
            );
        } catch (\Exception $e) {
            Log::error('Failed to send operating session ended notification', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
