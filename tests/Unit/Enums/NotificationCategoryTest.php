<?php

use App\Enums\NotificationCategory;

test('all categories have labels', function () {
    foreach (NotificationCategory::cases() as $category) {
        expect($category->label())->toBeString()->not->toBeEmpty();
    }
});

test('all categories have icons', function () {
    foreach (NotificationCategory::cases() as $category) {
        expect($category->icon())->toBeString()->not->toBeEmpty();
    }
});

test('all categories have debounce seconds', function () {
    foreach (NotificationCategory::cases() as $category) {
        expect($category->debounceSeconds())->toBeInt()->toBeGreaterThanOrEqual(0);
    }
});

test('all categories have descriptions', function () {
    foreach (NotificationCategory::cases() as $category) {
        expect($category->description())->toBeString()->not->toBeEmpty();
    }
});

test('specific category values match expected strings', function () {
    expect(NotificationCategory::NewSection->value)->toBe('new_section');
    expect(NotificationCategory::Guestbook->value)->toBe('guestbook');
    expect(NotificationCategory::Photos->value)->toBe('photos');
    expect(NotificationCategory::StationStatus->value)->toBe('station_status');
    expect(NotificationCategory::QsoMilestone->value)->toBe('qso_milestone');
    expect(NotificationCategory::Equipment->value)->toBe('equipment');
    expect(NotificationCategory::BulletinReminder->value)->toBe('bulletin_reminder');
    expect(NotificationCategory::WeatherAlert->value)->toBe('weather_alert');
});

test('debounce values match specification', function () {
    expect(NotificationCategory::NewSection->debounceSeconds())->toBe(120);
    expect(NotificationCategory::Guestbook->debounceSeconds())->toBe(180);
    expect(NotificationCategory::Photos->debounceSeconds())->toBe(300);
    expect(NotificationCategory::StationStatus->debounceSeconds())->toBe(120);
    expect(NotificationCategory::QsoMilestone->debounceSeconds())->toBe(0);
    expect(NotificationCategory::Equipment->debounceSeconds())->toBe(300);
    expect(NotificationCategory::BulletinReminder->debounceSeconds())->toBe(0);
    expect(NotificationCategory::WeatherAlert->debounceSeconds())->toBe(0);
});

test('category icons match specification', function () {
    expect(NotificationCategory::NewSection->icon())->toBe('o-globe-americas');
    expect(NotificationCategory::Guestbook->icon())->toBe('o-book-open');
    expect(NotificationCategory::Photos->icon())->toBe('o-photo');
    expect(NotificationCategory::StationStatus->icon())->toBe('o-signal');
    expect(NotificationCategory::QsoMilestone->icon())->toBe('o-trophy');
    expect(NotificationCategory::Equipment->icon())->toBe('o-wrench-screwdriver');
    expect(NotificationCategory::BulletinReminder->icon())->toBe('o-radio');
    expect(NotificationCategory::WeatherAlert->icon())->toBe('phosphor-cloud-lightning-duotone');
});

test('there are exactly nine categories', function () {
    expect(NotificationCategory::cases())->toHaveCount(9);
});

test('shift checkin reminder has correct properties', function () {
    $category = NotificationCategory::ShiftCheckinReminder;

    expect($category->value)->toBe('shift_checkin_reminder')
        ->and($category->label())->toBe('Shift Check-in Reminders')
        ->and($category->icon())->toBe('o-clock')
        ->and($category->debounceSeconds())->toBe(0)
        ->and($category->description())->toBe('Reminders before your scheduled shifts');
});
