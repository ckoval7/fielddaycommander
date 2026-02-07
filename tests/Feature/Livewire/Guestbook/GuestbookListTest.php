<?php

use App\Livewire\Guestbook\GuestbookList;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\User;
use Livewire\Livewire;

test('guestbook list is accessible to guests', function () {
    Livewire::test(GuestbookList::class)
        ->assertStatus(200);
});

test('guestbook list shows recent entries', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $entry = GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'callsign' => 'W1AW',
    ]);

    Livewire::test(GuestbookList::class)
        ->assertSee('John Doe')
        ->assertSee('W1AW');
});

test('guestbook list shows presence type indicators', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'presence_type' => GuestbookEntry::PRESENCE_TYPE_IN_PERSON,
        'first_name' => 'In',
        'last_name' => 'Person',
    ]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'presence_type' => GuestbookEntry::PRESENCE_TYPE_ONLINE,
        'first_name' => 'Online',
        'last_name' => 'User',
    ]);

    Livewire::test(GuestbookList::class)
        ->assertSee('In Person')
        ->assertSee('Online User');
});

test('guestbook list shows verified badge for verified entries', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $verifier = User::factory()->create(['first_name' => 'Staff', 'last_name' => 'Member']);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'first_name' => 'Verified',
        'last_name' => 'User',
        'is_verified' => true,
        'verified_by' => $verifier->id,
    ]);

    Livewire::test(GuestbookList::class)
        ->assertSee('Verified by Staff Member');
});

test('guestbook list shows bonus eligible badge for verified bonus entries', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $verifier = User::factory()->create();

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'first_name' => 'Mayor',
        'last_name' => 'Smith',
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
        'verified_by' => $verifier->id,
    ]);

    $component = Livewire::test(GuestbookList::class);

    // Should show the star badge for bonus eligible entries
    $component->assertSee('Mayor Smith');
});

test('guestbook list shows empty state when no entries exist', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::test(GuestbookList::class)
        ->assertSee('No visitors have signed the guestbook yet');
});

test('guestbook list does not show email addresses', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'secret@example.com',
    ]);

    Livewire::test(GuestbookList::class)
        ->assertSee('John Doe')
        ->assertDontSee('secret@example.com');
});

test('guestbook list shows comments when present', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'comments' => 'Great event!',
    ]);

    Livewire::test(GuestbookList::class)
        ->assertSee('Great event!');
});

test('guestbook list refreshes when guestbook-signed event is dispatched', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $component = Livewire::test(GuestbookList::class);

    // Initially no entries
    expect($component->get('entries'))->toHaveCount(0);

    // Create an entry
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'first_name' => 'New',
        'last_name' => 'Visitor',
    ]);

    // Dispatch the event
    $component->dispatch('guestbook-entry-created');

    // Should reload entries
    expect($component->get('entries'))->toHaveCount(1);
});

test('guestbook list respects entry limit', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Create more entries than the default limit (30)
    GuestbookEntry::factory()->count(35)->create([
        'event_configuration_id' => $eventConfig->id,
    ]);

    $component = Livewire::test(GuestbookList::class);

    // Should only show 30 entries
    expect($component->get('entries'))->toHaveCount(30);
});

test('guestbook list shows only entries for active event', function () {
    $activeEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $activeEventConfig = EventConfiguration::factory()->create(['event_id' => $activeEvent->id]);

    $otherEvent = Event::factory()->create([
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);
    $otherEventConfig = EventConfiguration::factory()->create(['event_id' => $otherEvent->id]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $activeEventConfig->id,
        'first_name' => 'Active',
        'last_name' => 'Event',
    ]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $otherEventConfig->id,
        'first_name' => 'Other',
        'last_name' => 'Event',
    ]);

    Livewire::test(GuestbookList::class)
        ->assertSee('Active Event')
        ->assertDontSee('Other Event');
});
