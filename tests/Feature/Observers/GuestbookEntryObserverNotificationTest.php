<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\NotificationCategory;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Facades\Notification;

test('creating guestbook entry fires guestbook notification', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'callsign' => 'W1AW',
    ]);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::Guestbook
            && str_contains($notification->message, 'John Doe')
            && str_contains($notification->message, 'W1AW');
    });
});

test('guestbook notification includes visitor name without callsign', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'callsign' => null,
    ]);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::Guestbook
            && str_contains($notification->message, 'Jane Smith')
            && ! str_contains($notification->message, '(');
    });
});

test('guestbook notification not sent to unsubscribed user', function () {
    Notification::fake();

    $subscribedUser = User::factory()->create();
    $unsubscribedUser = User::factory()->create([
        'notification_preferences' => [
            'categories' => [
                'guestbook' => false,
            ],
        ],
    ]);

    $eventConfig = EventConfiguration::factory()->create();

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
    ]);

    Notification::assertSentTo($subscribedUser, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::Guestbook;
    });

    Notification::assertNotSentTo($unsubscribedUser, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::Guestbook;
    });
});
