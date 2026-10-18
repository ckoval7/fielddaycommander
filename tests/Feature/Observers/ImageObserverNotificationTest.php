<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\NotificationCategory;
use App\Models\EventConfiguration;
use App\Models\Image;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Facades\Notification;

test('uploading image fires photos notification', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'uploaded_by_user_id' => $user->id,
        'caption' => 'Amazing antenna setup',
    ]);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) use ($user) {
        return $notification->category === NotificationCategory::Photos
            && str_contains($notification->message, $user->call_sign)
            && str_contains($notification->message, 'Amazing antenna setup');
    });
});

test('image notification works without caption', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'uploaded_by_user_id' => $user->id,
        'caption' => null,
    ]);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::Photos
            && str_contains($notification->message, 'uploaded a photo');
    });
});

test('image notification not sent to unsubscribed user', function () {
    Notification::fake();

    $subscribedUser = User::factory()->create();
    $unsubscribedUser = User::factory()->create([
        'notification_preferences' => [
            'categories' => [
                'photos' => false,
            ],
        ],
    ]);

    $eventConfig = EventConfiguration::factory()->create();

    Image::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'uploaded_by_user_id' => $subscribedUser->id,
    ]);

    Notification::assertSentTo($subscribedUser, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::Photos;
    });

    Notification::assertNotSentTo($unsubscribedUser, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::Photos;
    });
});
