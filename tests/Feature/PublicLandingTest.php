<?php

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
