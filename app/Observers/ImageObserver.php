<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Observers;

use App\Enums\NotificationCategory;
use App\Models\Image;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class ImageObserver
{
    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Handle the Image "created" event.
     */
    public function created(Image $image): void
    {
        try {
            $uploader = $image->uploader;
            $uploaderName = $uploader?->call_sign ?? 'Someone';
            $caption = $image->caption ? ": {$image->caption}" : '';

            $this->notificationService->notifyAll(
                category: NotificationCategory::Photos,
                title: 'New Photo Uploaded',
                message: "{$uploaderName} uploaded a photo{$caption}",
                url: '/gallery',
                groupKey: 'photo_uploads',
            );
        } catch (\Exception $e) {
            Log::error('Failed to send image notification', [
                'image_id' => $image->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
