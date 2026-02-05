<?php

use App\Livewire\Components\EventCountdown;
use App\Models\Event;
use App\Models\EventType;
use Livewire\Livewire;

beforeEach(function () {
    $this->eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);
});

test('component renders when upcoming event exists', function () {
    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    Livewire::test(EventCountdown::class)
        ->assertSee($event->name)
        ->assertSee('Starts in')
        ->assertSee('UPCOMING');
});

test('component renders when active event exists', function () {
    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);

    Livewire::test(EventCountdown::class)
        ->assertSee($event->name)
        ->assertSee('Ends in')
        ->assertSee('LIVE');
});

test('component renders when event recently ended', function () {
    $event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subDays(3),
        'end_time' => now()->subDays(2),
    ]);

    Livewire::test(EventCountdown::class)
        ->assertSee($event->name)
        ->assertSee('Ended')
        ->assertSee('ENDED');
});

test('component does not render when no relevant events exist', function () {
    Livewire::test(EventCountdown::class)
        ->assertDontSee('Starts in')
        ->assertDontSee('Ends in')
        ->assertDontSee('Ended');
});

test('component switches to upcoming event when recent event ended and next event within 4 weeks', function () {
    // Create an event that ended 2 weeks ago
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subDays(16),
        'end_time' => now()->subDays(14),
    ]);

    // Create upcoming event in 3 weeks
    $upcomingEvent = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(21),
        'end_time' => now()->addDays(22),
    ]);

    Livewire::test(EventCountdown::class)
        ->assertSee($upcomingEvent->name)
        ->assertSee('Starts in')
        ->assertSee('UPCOMING');
});

test('component shows ended state when next event is more than 4 weeks away', function () {
    // Create an event that ended 2 weeks ago
    $endedEvent = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subDays(16),
        'end_time' => now()->subDays(14),
    ]);

    // Create upcoming event in 6 weeks
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(42),
        'end_time' => now()->addDays(43),
    ]);

    Livewire::test(EventCountdown::class)
        ->assertSee($endedEvent->name)
        ->assertSee('Ended')
        ->assertSee('ENDED');
});

test('component displays both timezone and UTC times', function () {
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    $component = Livewire::test(EventCountdown::class);

    // Should display timezone abbreviation (not generic "Local")
    expect($component->get('timezoneLabel'))->not->toBeEmpty();

    // Should display UTC label
    $component->assertSee('UTC:');
});

test('component displays user preferred timezone abbreviation when user is authenticated', function () {
    $user = \App\Models\User::factory()->create([
        'preferred_timezone' => 'America/New_York',
    ]);

    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    $this->actingAs($user);

    $component = Livewire::test(EventCountdown::class);

    // Should see timezone abbreviation (EST or EDT depending on season)
    $timezoneLabel = $component->get('timezoneLabel');
    expect($timezoneLabel)->toBeIn(['EST', 'EDT']);

    // Should not see generic "Local:" label
    $component->assertDontSee('Local:');
});

test('component falls back to system timezone when user has no preferred timezone', function () {
    $user = \App\Models\User::factory()->create([
        'preferred_timezone' => null,
    ]);

    \App\Models\Setting::set('timezone', 'America/Chicago');

    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    $this->actingAs($user);

    $component = Livewire::test(EventCountdown::class);

    // Should see system timezone abbreviation (CST or CDT)
    $timezoneLabel = $component->get('timezoneLabel');
    expect($timezoneLabel)->toBeIn(['CST', 'CDT']);
});

test('component uses system timezone when no user is authenticated', function () {
    \App\Models\Setting::set('timezone', 'America/Los_Angeles');

    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    $component = Livewire::test(EventCountdown::class);

    // Should see system timezone abbreviation (PST or PDT)
    $timezoneLabel = $component->get('timezoneLabel');
    expect($timezoneLabel)->toBeIn(['PST', 'PDT']);
});

test('component displays correct timezone abbreviation for different timezones', function () {
    $testCases = [
        ['timezone' => 'America/Denver', 'expected' => ['MST', 'MDT']],
        ['timezone' => 'Europe/London', 'expected' => ['GMT', 'BST']],
        ['timezone' => 'Asia/Tokyo', 'expected' => ['JST']],
        ['timezone' => 'Australia/Sydney', 'expected' => ['AEDT', 'AEST']],
    ];

    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    foreach ($testCases as $testCase) {
        $user = \App\Models\User::factory()->create([
            'preferred_timezone' => $testCase['timezone'],
        ]);

        $this->actingAs($user);

        $component = Livewire::test(EventCountdown::class);
        $timezoneLabel = $component->get('timezoneLabel');

        expect($timezoneLabel)->toBeIn($testCase['expected']);
    }
});

test('component always uses 1 second polling for real-time clock updates', function () {
    // Even when event is far away, we want clocks to tick every second
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    Livewire::test(EventCountdown::class)
        ->assertSet('pollingInterval', 1);
});

test('countdown formats correctly for days remaining', function () {
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(2)->addHours(5)->addMinutes(30),
        'end_time' => now()->addDays(3),
    ]);

    $component = Livewire::test(EventCountdown::class);
    expect($component->get('formattedCountdown'))->toContain('d');
    expect($component->get('formattedCountdown'))->toContain('h');
    expect($component->get('formattedCountdown'))->toContain('m');
});

test('countdown formats correctly for hours remaining', function () {
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addHours(5)->addMinutes(30),
        'end_time' => now()->addDays(1),
    ]);

    $component = Livewire::test(EventCountdown::class);
    expect($component->get('formattedCountdown'))->toContain('h');
    expect($component->get('formattedCountdown'))->toContain('m');
    expect($component->get('formattedCountdown'))->not->toContain('d');
});

test('countdown formats correctly for minutes remaining', function () {
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addMinutes(30)->addSeconds(15),
        'end_time' => now()->addDays(1),
    ]);

    $component = Livewire::test(EventCountdown::class);
    expect($component->get('formattedCountdown'))->toContain('m');
    expect($component->get('formattedCountdown'))->toContain('s');
    expect($component->get('formattedCountdown'))->not->toContain('h');
});

test('component prioritizes in-progress event over upcoming event', function () {
    // Create an upcoming event
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    // Create an in-progress event
    $activeEvent = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);

    Livewire::test(EventCountdown::class)
        ->assertSee($activeEvent->name)
        ->assertSee('Ends in')
        ->assertSee('LIVE');
});

test('component updates when updateComponent is called', function () {
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);

    Livewire::test(EventCountdown::class)
        ->call('updateComponent')
        ->assertSee('Starts in');
});

test('component does not render when event ended more than 4 weeks ago and no upcoming events', function () {
    Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subDays(35),
        'end_time' => now()->subDays(30), // Clearly more than 28 days
    ]);

    $component = Livewire::test(EventCountdown::class);

    // Component should render (root div) but have no event
    expect($component->get('event'))->toBeNull();
});
