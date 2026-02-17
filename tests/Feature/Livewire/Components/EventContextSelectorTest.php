<?php

use App\Livewire\Components\EventContextSelector;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('component renders with active event', function () {
    $event = Event::factory()->create([
        'name' => 'Field Day 2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextSelector::class)
        ->assertSee('Field Day 2025')
        ->assertSee('Active');
});

test('component renders with no event', function () {
    Livewire::actingAs($this->user)
        ->test(EventContextSelector::class)
        ->assertSee('No Event Selected');
});

test('component shows available events', function () {
    $active = Event::factory()->create([
        'name' => 'FD 2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    $past = Event::factory()->create([
        'name' => 'FD 2024',
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);
    EventConfiguration::factory()->create(['event_id' => $past->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextSelector::class)
        ->assertSee('FD 2025')
        ->assertSee('FD 2024');
});

test('switchEvent sets session', function () {
    $active = Event::factory()->create([
        'name' => 'FD 2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    $past = Event::factory()->create([
        'name' => 'FD 2024',
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);
    EventConfiguration::factory()->create(['event_id' => $past->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextSelector::class)
        ->call('switchEvent', $past->id)
        ->assertRedirect();

    expect(session('viewing_event_id'))->toBe($past->id);
});

test('returnToActive clears session', function () {
    $active = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    session(['viewing_event_id' => 999]);

    Livewire::actingAs($this->user)
        ->test(EventContextSelector::class)
        ->call('returnToActive')
        ->assertRedirect();

    expect(session('viewing_event_id'))->toBeNull();
});

test('component groups events by status', function () {
    // Active event
    $active = Event::factory()->create([
        'name' => 'Active Event',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    // Upcoming event
    $upcoming = Event::factory()->create([
        'name' => 'Upcoming Event',
        'start_time' => now()->addDays(30),
        'end_time' => now()->addDays(31),
    ]);
    EventConfiguration::factory()->create(['event_id' => $upcoming->id]);

    // Archived event (well past grace period)
    $archived = Event::factory()->create([
        'name' => 'Archived Event',
        'start_time' => now()->subDays(120),
        'end_time' => now()->subDays(119),
    ]);
    EventConfiguration::factory()->create(['event_id' => $archived->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextSelector::class)
        ->assertSee('Active Event')
        ->assertSee('Upcoming Event')
        ->assertSee('Archived Event');
});

test('component shows return to active button when viewing past event', function () {
    $active = Event::factory()->create([
        'name' => 'Current Event',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    $past = Event::factory()->create([
        'name' => 'Old Event',
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);
    EventConfiguration::factory()->create(['event_id' => $past->id]);

    // Set session to view the past event
    session(['viewing_event_id' => $past->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextSelector::class)
        ->assertSee('Old Event')
        ->assertSee('Return to Active Event');
});

test('component does not show return to active button when viewing active event', function () {
    $active = Event::factory()->create([
        'name' => 'Current Event',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextSelector::class)
        ->assertSee('Current Event')
        ->assertDontSee('Return to Active Event');
});
