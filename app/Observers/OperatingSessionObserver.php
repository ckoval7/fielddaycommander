<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Observers;

use App\Enums\NotificationCategory;
use App\Events\StationStatusChanged;
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

        $this->broadcastStationStatus($session);

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

        if ($session->wasChanged(['end_time', 'band_id', 'mode_id', 'operator_user_id', 'station_id'])) {
            $this->broadcastStationStatus($session);
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

    /**
     * Tell open stations pages that this session's station changed.
     */
    protected function broadcastStationStatus(OperatingSession $session): void
    {
        try {
            StationStatusChanged::notify($session->station_id);
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast station status change', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
