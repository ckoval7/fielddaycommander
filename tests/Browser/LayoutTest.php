<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\StationDevice;
use App\Support\StationMode;
use Illuminate\Cookie\CookieValuePrefix;
use Tests\Browser\Support\FieldDayScene;
use Tests\Browser\Support\LayoutAudit;

/*
 * Every screen at phone, tablet and desktop widths, plus states that only
 * appear after an action: no sideways scroll; no controls off screen,
 * overlapping or covered; nothing spilling out of its card, tile or button;
 * touch-sized targets and 16px field text on touch screens; and no stacked
 * side padding on phones.
 */

dataset('screens', [
    'dashboard' => '/',
    'tv dashboard' => '/dashboard/tv',
    'logging session' => '/logging/session/{session}',
    'transcribe station picker' => '/logging/transcribe',
    'transcribe' => '/logging/transcribe/{station}',
    'logbook' => '/logbook',
    'events' => '/events',
    'event' => '/events/{event}',
    'create event' => '/events/create',
    'edit event' => '/events/{event}/edit',
    'event equipment' => '/events/{event}/equipment',
    'event guestbook' => '/events/{event}/guestbook',
    'messages' => '/events/{event}/messages',
    'new message' => '/events/{event}/messages/create',
    'stations' => '/stations',
    'create station' => '/stations/create',
    'edit station' => '/stations/{station}/edit',
    'schedule' => '/schedule',
    'schedule dashboard' => '/schedule/dashboard',
    'manage schedule' => '/schedule/manage',
    'my shifts' => '/schedule/my-shifts',
    'my equipment' => '/equipment',
    'all equipment' => '/equipment/all',
    'club equipment' => '/equipment/club',
    'create equipment' => '/equipment/create',
    'guestbook' => '/guestbook',
    'gallery' => '/gallery',
    'album' => '/gallery/{config}',
    'upload photos' => '/gallery/{config}/upload',
    'users' => '/users',
    'profile' => '/profile',
    'settings' => '/settings',
    'audit logs' => '/admin/audit-logs',
    'developer' => '/admin/developer',
    'external loggers' => '/admin/external-loggers',
    'adif import' => '/admin/import/adif',
    'scoring' => '/scoring',
    'reports' => '/reports',
    'weather' => '/weather',
    'manage weather' => '/weather/manage',
    'site safety' => '/site-safety',
    'manage site safety' => '/site-safety/manage',
    'section map' => '/section-map',
    'w1aw bulletin' => '/w1aw-bulletin',
]);

dataset('viewports', [
    'phone 360' => [360, 740, true],
    'tablet 810' => [810, 1080, true],
    'tablet 1024' => [1024, 768, true],
    'desktop 1280' => [1280, 800, false],
]);

/**
 * Opens a path at a viewport size, as a touch device or with a mouse.
 */
function visitAt(string $path, int $width, int $height, bool $touch): object
{
    $page = visit($path);
    $page = $touch ? $page->on()->mobile() : $page->on()->desktop();

    return $page->resize($width, $height);
}

it('lays out signed-in screens cleanly', function (string $path, int $width, int $height, bool $touch) {
    $scene = FieldDayScene::create();
    $this->actingAs($scene->admin);

    LayoutAudit::assertClean(visitAt($scene->path($path), $width, $height, $touch));
})->with('screens')->with('viewports');

it('lays out the station picker cleanly', function (int $width, int $height, bool $touch) {
    $scene = FieldDayScene::create();
    $scene->session->update(['end_time' => now()]);
    $this->actingAs($scene->admin);

    LayoutAudit::assertClean(visitAt('/logging', $width, $height, $touch));
})->with('viewports');

it('lays out public screens cleanly', function (string $path, int $width, int $height, bool $touch) {
    FieldDayScene::create();

    LayoutAudit::assertClean(visitAt($path, $width, $height, $touch));
})->with([
    'login' => '/login',
    'public page' => '/public',
    'guestbook kiosk' => '/guestbook/kiosk',
    'station mode' => '/station-mode',
])->with('viewports');

it('lays out Winter Field Day screens cleanly', function (string $path, int $width, int $height, bool $touch) {
    $scene = FieldDayScene::create('WFD');
    $this->actingAs($scene->admin);

    LayoutAudit::assertClean(visitAt($scene->path($path), $width, $height, $touch));
})->with([
    'dashboard' => '/',
    'event guestbook' => '/events/{event}/guestbook',
    'scoring' => '/scoring',
    'reports' => '/reports',
    'event' => '/events/{event}',
])->with('viewports');

it('lays out the logbook with rows selected', function (int $width, int $height, bool $touch) {
    $scene = FieldDayScene::create();
    $this->actingAs($scene->admin);

    $page = visitAt('/logbook', $width, $height, $touch);
    $page->script('() => document.querySelector(\'[aria-label="Select all contacts on this page"]\').click()');

    LayoutAudit::assertClean($page);
})->with('viewports');

it('keeps a modal\'s buttons clear of the validation summary', function (int $width, int $height, bool $touch) {
    $scene = FieldDayScene::create();
    $this->actingAs($scene->admin);

    $page = visitAt('/users', $width, $height, $touch);
    $page->script('() => [...document.querySelectorAll("button")].find((b) => b.textContent.includes("Add User")).click()');
    $page->wait(0.8);
    $page->script('() => [...document.querySelectorAll(".modal-box button")].find((b) => b.textContent.includes("Create User")).click()');
    $page->wait(0.8);

    LayoutAudit::assertClean($page, skip: ['gutter']);
})->with('viewports');

it('lays out station mode on an enrolled browser', function (int $width, int $height, bool $touch) {
    $scene = FieldDayScene::create();
    $token = str_repeat('a', 64);
    StationDevice::create([
        'station_id' => $scene->station->id,
        'token_hash' => StationDevice::hashToken($token),
        'name' => 'Front table laptop',
        'enrolled_by_user_id' => $scene->admin->id,
    ]);
    $cookie = encrypt(CookieValuePrefix::create(StationMode::COOKIE, app('encrypter')->getKey()).$token, false);

    $page = visitAt('/login', $width, $height, $touch);
    $page->script("() => { document.cookie = 'station_device={$cookie}; path=/'; location.href = '/station-mode'; }");
    $page->wait(1.5);

    expect($page->url())->toContain('/station-mode');
    LayoutAudit::assertClean($page);
})->with('viewports');
