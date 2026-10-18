<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Setting::set('setup_completed', 'true');
});

test('app brand displays uploaded logo', function () {
    $logo = UploadedFile::fake()->image('logo.png');
    $path = $logo->storeAs('branding', 'test-logo.png', 'public');
    Setting::set('site_logo_path', $path);

    $this->get('/')
        ->assertOk()
        ->assertSee('branding/test-logo.png', escape: false);
});

test('app brand shows icon placeholder when no logo uploaded', function () {
    Setting::set('site_logo_path', '');

    $this->get('/')
        ->assertOk()
        ->assertDontSee('branding/', escape: false);
});

test('collapsed mobile header shows the event callsign, not the site name', function () {
    Setting::set('site_name', 'Field Day Commander Demo');
    $event = Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addDay()]);
    EventConfiguration::factory()->create(['event_id' => $event->id, 'is_active' => true, 'callsign' => 'W1FDC']);

    $this->get('/')
        ->assertOk()
        ->assertSee('canCollapse: true', escape: false)
        ->assertSeeInOrder(['x-show="scrolled && canCollapse"', 'W1FDC'], escape: false);
});

test('mobile header does not collapse without an event callsign', function () {
    Setting::set('site_name', 'Field Day Commander Demo');

    $this->get('/')
        ->assertOk()
        ->assertSee('canCollapse: false', escape: false);
});
