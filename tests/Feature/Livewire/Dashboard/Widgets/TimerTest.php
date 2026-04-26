<?php

use App\Livewire\Dashboard\Widgets\Timer;
use App\Models\Event;
use App\Models\EventType;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2025-06-28 18:00:00');
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\EventTypeSeeder']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('implements IsWidget trait', function () {
    $traits = class_uses_recursive(Timer::class);

    expect($traits)->toContain(\App\Livewire\Dashboard\Widgets\Concerns\IsWidget::class);
});

it('displays countdown for active event', function () {
    $eventType = EventType::where('code', 'FD')->first();

    // Event ending in 2 days, 5 hours, 30 minutes
    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addDays(2)->addHours(5)->addMinutes(30),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertViewHas('data', function ($data) {
            return $data['is_ended'] === false
                && $data['label'] === 'Time Remaining'
                && isset($data['end_time'])
                && isset($data['now']);
        });
});

it('returns ended state when event has passed', function () {
    $eventType = EventType::where('code', 'FD')->first();

    // Event that ended 1 hour ago (still returned via grace period)
    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(26),
        'end_time' => now()->subHour(),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertViewHas('data', function ($data) {
            return $data['is_ended'] === true
                && $data['label'] === 'Event Ended';
        });
});

it('returns no event state when no active event exists', function () {
    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertViewHas('data', function ($data) {
            return $data['is_ended'] === true
                && $data['label'] === 'No Active Event'
                && $data['end_time'] === null;
        });
});

it('provides end time as ISO 8601 string', function () {
    $eventType = EventType::where('code', 'FD')->first();

    $endTime = now()->addHours(5);
    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(1),
        'end_time' => $endTime,
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertViewHas('data', function ($data) use ($endTime) {
            return $data['end_time'] === $endTime->toIso8601String();
        });
});

it('provides current time as ISO 8601 string', function () {
    $eventType = EventType::where('code', 'FD')->first();

    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(1),
        'end_time' => now()->addHours(5),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertViewHas('data', function ($data) {
            return isset($data['now'])
                && str_contains($data['now'], 'T')
                && str_contains($data['now'], '+');
        });
});

it('does not cache data for real-time countdown', function () {
    $timer = Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ]);

    expect($timer->instance()->shouldCache())->toBeFalse();
});

it('returns empty widget listeners array', function () {
    $timer = Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ]);

    expect($timer->instance()->getWidgetListeners())->toBe([]);
});

it('supports normal size variant', function () {
    $eventType = EventType::where('code', 'FD')->first();

    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(1),
        'end_time' => now()->addHours(5),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertSee('Time Remaining');
});

it('supports tv size variant', function () {
    $eventType = EventType::where('code', 'FD')->first();

    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(1),
        'end_time' => now()->addHours(5),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'tv',
    ])
        ->assertOk()
        ->assertSee('Time Remaining')
        ->assertSee('sm:text-6xl'); // TV-specific font cap
});

it('renders Alpine.js countdown component', function () {
    $eventType = EventType::where('code', 'FD')->first();

    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(1),
        'end_time' => now()->addHours(5),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertSee('x-data')
        ->assertSee('countdown');
});

it('renders event ended message when event is over', function () {
    $eventType = EventType::where('code', 'FD')->first();

    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(26),
        'end_time' => now()->subHour(),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertSee('Event Ended');
});

it('renders no active event message when no event exists', function () {
    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertSee('No Active Event');
});

it('handles event with less than 24 hours remaining', function () {
    $eventType = EventType::where('code', 'FD')->first();

    // Event ending in 5 hours
    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(19),
        'end_time' => now()->addHours(5),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertViewHas('data', function ($data) {
            $endTime = Carbon::parse($data['end_time']);
            $now = Carbon::parse($data['now']);

            return $now->diffInHours($endTime) < 24;
        });
});

it('handles event with more than 24 hours remaining', function () {
    $eventType = EventType::where('code', 'FD')->first();

    // Event ending in 2 days
    Event::factory()->create([
        'name' => '2025 Field Day',
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(1),
        'end_time' => now()->addDays(2),
    ]);

    Livewire::test(Timer::class, [
        'config' => ['timer_type' => 'event_countdown'],
        'size' => 'normal',
    ])
        ->assertOk()
        ->assertViewHas('data', function ($data) {
            $endTime = Carbon::parse($data['end_time']);
            $now = Carbon::parse($data['now']);

            return $now->diffInHours($endTime) >= 24;
        });
});
