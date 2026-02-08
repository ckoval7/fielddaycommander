<?php

use App\Livewire\Dashboard\Widgets\ProgressBar;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    // Clear cache before each test
    Cache::flush();
});

test('progress bar component can be instantiated', function () {
    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $component->assertStatus(200);
});

test('progress bar returns empty progress when no active event', function () {
    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->toHaveKeys(['current', 'target', 'percentage'])
        ->and($data['current'])->toBe(0)
        ->and($data['target'])->toBe(50)
        ->and($data['percentage'])->toBe(0);
});

test('progress bar calculates milestone progress correctly', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create 25 contacts (50% to milestone of 50)
    Contact::factory()->count(25)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['current'])->toBe(25)
        ->and($data['target'])->toBe(50)
        ->and($data['percentage'])->toBe(50.0);
});

test('progress bar advances to next milestone after reaching 50', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create 75 contacts (past first milestone, 25 toward second)
    Contact::factory()->count(75)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['current'])->toBe(75)
        ->and($data['target'])->toBe(100) // Next milestone is 100
        ->and($data['percentage'])->toBe(75.0); // 75/100 = 75%
});

test('progress bar shows 100% when exactly at milestone', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create exactly 50 contacts
    Contact::factory()->count(50)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['current'])->toBe(50)
        ->and($data['target'])->toBe(100) // Next milestone is 100
        ->and($data['percentage'])->toBe(50.0); // 50/100 = 50%
});

test('progress bar excludes duplicate contacts', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create 20 valid contacts
    Contact::factory()->count(20)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // Create 10 duplicate contacts
    Contact::factory()->count(10)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => true,
    ]);

    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['current'])->toBe(20); // Only non-duplicates counted
});

test('progress bar handles large contact counts', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create 575 contacts (past 11 milestones, 25 toward 12th)
    Contact::factory()->count(575)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['current'])->toBe(575)
        ->and($data['target'])->toBe(600) // Next milestone is 600
        ->and($data['percentage'])->toBe(95.8); // 575/600 = 95.8%
});

test('progress bar caches results for 3 seconds', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    Contact::factory()->count(25)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // First call - should cache
    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data1 = $component->viewData('data');
    expect($data1['current'])->toBe(25);

    // Add more contacts
    Contact::factory()->count(10)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // Second call - should return cached value
    $component2 = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data2 = $component2->viewData('data');
    expect($data2['current'])->toBe(25); // Still cached value
});

test('progress bar uses IsWidget trait', function () {
    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'tv',
        'widgetId' => 'test-progress-123',
    ]);

    expect($component->get('size'))->toBe('tv')
        ->and($component->get('widgetId'))->toBe('test-progress-123')
        ->and($component->get('config'))->toBe(['metric' => 'next_milestone']);
});

test('progress bar calculates percentage correctly for edge cases', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create 1 contact (2% of 50)
    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['current'])->toBe(1)
        ->and($data['target'])->toBe(50)
        ->and($data['percentage'])->toBe(2.0); // 1/50 = 2%
});

test('progress bar returns empty listeners array', function () {
    $component = Livewire::test(ProgressBar::class, [
        'config' => ['metric' => 'next_milestone'],
        'size' => 'normal',
    ]);

    $listeners = $component->instance()->getWidgetListeners();

    expect($listeners)->toBeArray()->toBeEmpty();
});
