<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;

beforeEach(function () {
    Setting::set('setup_completed', 'true');
    $this->event = Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);
});

it('shows public visitor details when the site is open to the public', function () {
    EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'club_name' => 'Test Radio Club',
        'is_open_to_public' => true,
        'public_directions' => 'Behind the library',
        'public_visiting_hours' => 'Saturday 10am to 4pm',
        'public_contact_email' => 'info@example.org',
        'public_contact_phone' => '+1 (555) 010-0100',
        'public_website_url' => 'https://example.org/club',
        'public_description' => 'Come see ham radio in action.',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Visit Us')
        ->assertSee('hosted by Test Radio Club')
        ->assertSee('Behind the library')
        ->assertSee('Saturday 10am to 4pm')
        ->assertSee('info@example.org')
        ->assertSee('href="mailto:info@example.org"', false)
        ->assertSee('href="tel:+15550100100"', false)
        ->assertSee('+1 (555) 010-0100')
        ->assertSee('href="https://example.org/club" target="_blank" rel="noopener nofollow"', false)
        ->assertSee('Come see ham radio in action.');
});

it('hides public visitor details when the site is not open to the public', function () {
    EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'is_open_to_public' => false,
        'public_directions' => 'Secret bunker',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('Visit Us')
        ->assertDontSee('Secret bunker');
});

it('escapes public visitor details on output', function () {
    EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'is_open_to_public' => true,
        'public_description' => '<script>alert(1)</script>',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('escapes public contact links on output', function () {
    EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'is_open_to_public' => true,
        'public_contact_phone' => '555"><script>alert(2)</script>',
        'public_website_url' => 'https://example.org/"><script>alert(3)</script>',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertDontSee('<script>alert(2)</script>', false)
        ->assertDontSee('<script>alert(3)</script>', false);
});
