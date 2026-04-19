<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    Setting::set('setup_completed', 'true');
});

it('shows the public landing page to unauthenticated visitors at /', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertViewIs('public-landing');
});

it('does not show the public landing page to logged-in users at /', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
    // Auth'd users should see one of the auth'd dashboard views, not the public landing
    expect($response->original->name())->not->toBe('public-landing');
});

it('shows the public landing page at /public for anyone', function () {
    $response = $this->get('/public');

    $response->assertStatus(200);
    $response->assertViewIs('public-landing');
});

it('shows the public landing page at /public for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/public');

    $response->assertStatus(200);
    $response->assertViewIs('public-landing');
});

it('renders live stats widgets when an active event exists', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);

    EventConfiguration::factory()->create([
        'event_id' => $event->id,
    ]);

    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('Live Stats');
});

it('shows no-event message when no active event exists', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('No active event at this time');
});

it('shows talk-in frequency when set on the active event configuration', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);

    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'talk_in_frequency' => '146.52 MHz FM',
    ]);

    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('Talk-in: 146.52 MHz FM');
});

it('hides talk-in frequency when not set', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);

    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'talk_in_frequency' => null,
    ]);

    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertDontSee('Talk-in:');
});
