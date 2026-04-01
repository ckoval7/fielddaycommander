<?php

use App\Enums\NotificationCategory;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Facades\Notification;

test('new section contact fires new_section notification', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $section = Section::where('code', 'CT')->first()
        ?? Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1']);

    $session = OperatingSession::factory()->create([
        'station_id' => $eventConfig->stations()->create([
            'name' => 'Station 1',
            'event_configuration_id' => $eventConfig->id,
        ])->id,
        'operator_user_id' => $user->id,
    ]);

    // Clear notifications from session creation
    Notification::fake();

    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'section_id' => $section->id,
        'callsign' => 'W1AW',
    ]);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) use ($user) {
        return $notification->category === NotificationCategory::NewSection
            && str_contains($notification->message, 'CT')
            && str_contains($notification->message, $user->call_sign)
            && ! str_contains($notification->message, 'W1AW');
    });
});

test('duplicate section contact does not fire new_section notification', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $section = Section::where('code', 'CT')->first()
        ?? Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1']);

    $session = OperatingSession::factory()->create([
        'station_id' => $eventConfig->stations()->create([
            'name' => 'Station 1',
            'event_configuration_id' => $eventConfig->id,
        ])->id,
        'operator_user_id' => $user->id,
    ]);

    // First contact with this section
    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'section_id' => $section->id,
    ]);

    // Reset fake to track only the second contact
    Notification::fake();

    // Second contact with same section
    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'section_id' => $section->id,
    ]);

    Notification::assertNotSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::NewSection;
    });
});

test('contact without section does not fire new_section notification', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $eventConfig->stations()->create([
            'name' => 'Station 1',
            'event_configuration_id' => $eventConfig->id,
        ])->id,
        'operator_user_id' => $user->id,
    ]);

    // Clear notifications from session creation
    Notification::fake();

    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'section_id' => null,
    ]);

    Notification::assertNotSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::NewSection;
    });
});

test('qso milestone fires notification at every 50 qsos', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $eventConfig->stations()->create([
            'name' => 'Station 1',
            'event_configuration_id' => $eventConfig->id,
        ])->id,
        'operator_user_id' => $user->id,
    ]);

    // Create 49 contacts (no milestone yet)
    Contact::factory()->count(49)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'is_duplicate' => false,
    ]);

    // Reset and create the 50th
    Notification::fake();

    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'is_duplicate' => false,
    ]);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::QsoMilestone
            && str_contains($notification->message, '50');
    });
});

test('two new sections within debounce window produce one notification', function () {
    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $sectionCT = Section::where('code', 'CT')->first()
        ?? Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1']);
    $sectionNY = Section::where('code', 'NY')->first()
        ?? Section::create(['code' => 'NY', 'name' => 'New York', 'region' => 'W2']);

    $session = OperatingSession::factory()->create([
        'station_id' => $eventConfig->stations()->create([
            'name' => 'Station 1',
            'event_configuration_id' => $eventConfig->id,
        ])->id,
        'operator_user_id' => $user->id,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'section_id' => $sectionCT->id,
        'callsign' => 'W1AW',
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'section_id' => $sectionNY->id,
        'callsign' => 'W2AW',
    ]);

    $user->refresh();
    $newSectionNotifications = $user->notifications->filter(
        fn ($n) => ($n->data['category'] ?? '') === 'new_section'
    );
    expect($newSectionNotifications)->toHaveCount(1);
    expect($newSectionNotifications->first()->data['count'])->toBe(2);
});

test('duplicate qsos do not count toward milestone', function () {
    Notification::fake();

    $user = User::factory()->create();
    $eventConfig = EventConfiguration::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $eventConfig->stations()->create([
            'name' => 'Station 1',
            'event_configuration_id' => $eventConfig->id,
        ])->id,
        'operator_user_id' => $user->id,
    ]);

    // Create 49 non-duplicate contacts
    Contact::factory()->count(49)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'is_duplicate' => false,
    ]);

    // Reset notifications
    Notification::fake();

    // 50th contact is a duplicate - should NOT trigger milestone
    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'is_duplicate' => true,
    ]);

    Notification::assertNotSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::QsoMilestone;
    });
});
