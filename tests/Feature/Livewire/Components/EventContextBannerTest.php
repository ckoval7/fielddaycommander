<?php

use App\Livewire\Components\EventContextBanner;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('banner is hidden when viewing active event', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextBanner::class)
        ->assertDontSee('Viewing:');
});

test('banner shows when viewing past event in grace period', function () {
    Setting::set('post_event_grace_period_days', 30);

    $active = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    $past = Event::factory()->create([
        'name' => 'FD 2024',
        'start_time' => now()->subDays(10),
        'end_time' => now()->subDays(9),
    ]);
    EventConfiguration::factory()->create(['event_id' => $past->id]);

    session(['viewing_event_id' => $past->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextBanner::class)
        ->assertSee('FD 2024')
        ->assertSee('Grace Period');
});

test('banner shows archived status for old events', function () {
    Setting::set('post_event_grace_period_days', 30);

    $past = Event::factory()->create([
        'name' => 'FD 2023',
        'start_time' => now()->subDays(400),
        'end_time' => now()->subDays(399),
    ]);
    EventConfiguration::factory()->create(['event_id' => $past->id]);

    session(['viewing_event_id' => $past->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextBanner::class)
        ->assertSee('FD 2023')
        ->assertSee('Read Only');
});

test('banner has return to active event button when active event exists', function () {
    $active = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    $past = Event::factory()->create([
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);
    EventConfiguration::factory()->create(['event_id' => $past->id]);

    session(['viewing_event_id' => $past->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextBanner::class)
        ->assertSee('Return to Active Event');
});

test('returnToActive clears session', function () {
    $active = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $active->id]);

    $past = Event::factory()->create([
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);

    session(['viewing_event_id' => $past->id]);

    Livewire::actingAs($this->user)
        ->test(EventContextBanner::class)
        ->call('returnToActive')
        ->assertRedirect();

    expect(session('viewing_event_id'))->toBeNull();
});

test('banner is hidden when no session override', function () {
    Livewire::actingAs($this->user)
        ->test(EventContextBanner::class)
        ->assertDontSee('Viewing:');
});
