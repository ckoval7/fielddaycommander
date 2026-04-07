<?php

use App\Events\ContactLogged;
use App\Models\Contact;
use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\Event;

// Create fixtures before setting the cookie to avoid recording factory side-effects
beforeEach(function () {
    config(['demo.enabled' => true]);

    $this->event = Event::factory()->create();
    $this->contact = Contact::factory()->create();

    $this->uuid = fake()->uuid();
    $this->demoSession = DemoSession::create([
        'session_uuid' => $this->uuid,
        'role' => 'operator',
        'visitor_hash' => hash('sha256', 'test'),
        'user_agent' => 'Test',
        'device_type' => 'desktop',
        'provisioned_at' => now(),
        'last_seen_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    // Set the demo_session cookie after creating fixtures so factory side-effects are not tracked
    $this->app['request']->cookies->set('demo_session', $this->uuid.'|operator');
});

it('logs contact.logged event when ContactLogged fires', function () {
    event(new ContactLogged($this->contact, $this->event));

    expect(DemoEvent::where('demo_session_id', $this->demoSession->id)
        ->where('name', 'contact.logged')
        ->exists()
    )->toBeTrue();

    $this->demoSession->refresh();
    expect($this->demoSession->total_actions)->toBe(1);
});

it('records contact metadata in the demo event', function () {
    event(new ContactLogged($this->contact, $this->event));

    $demoEvent = DemoEvent::where('demo_session_id', $this->demoSession->id)
        ->where('name', 'contact.logged')
        ->first();

    expect($demoEvent)->not->toBeNull();
    expect($demoEvent->metadata)->toHaveKey('callsign', $this->contact->callsign);
    expect($demoEvent->type)->toBe('action');
});

it('does not log events when demo mode is disabled', function () {
    config(['demo.enabled' => false]);

    event(new ContactLogged($this->contact, $this->event));

    expect(DemoEvent::where('demo_session_id', $this->demoSession->id)->count())->toBe(0);
});

it('does not log events when no demo session cookie', function () {
    $this->app['request']->cookies->remove('demo_session');

    event(new ContactLogged($this->contact, $this->event));

    expect(DemoEvent::where('demo_session_id', $this->demoSession->id)->count())->toBe(0);
});

it('does not log events when cookie has invalid uuid', function () {
    $this->app['request']->cookies->set('demo_session', 'not-a-uuid|operator');

    event(new ContactLogged($this->contact, $this->event));

    expect(DemoEvent::where('demo_session_id', $this->demoSession->id)->count())->toBe(0);
});

it('does not log events when demo session does not exist in database', function () {
    $this->app['request']->cookies->set('demo_session', fake()->uuid().'|operator');

    event(new ContactLogged($this->contact, $this->event));

    expect(DemoEvent::count())->toBe(0);
});

it('increments total_actions on the demo session', function () {
    // Reuse the same OperatingSession to avoid triggering session.started events
    $contact2 = Contact::factory()->create([
        'operating_session_id' => $this->contact->operating_session_id,
        'event_configuration_id' => $this->contact->event_configuration_id,
    ]);

    event(new ContactLogged($this->contact, $this->event));
    event(new ContactLogged($contact2, $this->event));

    $this->demoSession->refresh();
    expect($this->demoSession->total_actions)->toBe(2);
});
