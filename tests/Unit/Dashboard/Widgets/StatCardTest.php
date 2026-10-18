<?php

use App\Livewire\Dashboard\Widgets\StatCard;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
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

test('stat card component can be instantiated', function () {
    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    $component->assertStatus(200);
});

test('stat card returns empty metric when no active event', function () {
    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->toHaveKeys(['value', 'label', 'icon', 'color'])
        ->and($data['value'])->toBe('0')
        ->and($data['label'])->toBe('QSOs');
});

test('stat card calculates total score correctly', function () {
    // Create active event
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Create contacts with points
    $user = User::factory()->create();
    $station = Station::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'max_power_watts' => 100, // Keep at/below 100W so power multiplier is 2x
    ]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'points' => 2,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'total_score'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('20') // 5 contacts * 2 points * 2x power multiplier
        ->and($data['label'])->toBe('Total Score')
        ->and($data['icon'])->toBe('phosphor-trophy')
        ->and($data['color'])->toBe('text-success');
});

test('stat card calculates qso count correctly', function () {
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

    Contact::factory()->count(7)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('7')
        ->and($data['label'])->toBe('QSOs')
        ->and($data['icon'])->toBe('phosphor-chat-centered-dots')
        ->and($data['color'])->toBe('text-primary');
});

test('stat card excludes duplicate contacts from count', function () {
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

    // Create 5 valid contacts
    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // Create 3 duplicate contacts
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => true,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('5'); // Only non-duplicates counted
});

test('stat card calculates sections worked correctly', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create 3 different sections
    $sections = Section::factory()->count(3)->create();

    // Create 2 contacts for section 1
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $sections[0]->id,
        'is_duplicate' => false,
    ]);

    // Create 1 contact for section 2
    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $sections[1]->id,
        'is_duplicate' => false,
    ]);

    // Create 1 contact for section 3
    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $sections[2]->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'sections_worked'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('3') // 3 unique sections
        ->and($data['label'])->toBe('Sections')
        ->and($data['icon'])->toBe('phosphor-map-trifold')
        ->and($data['color'])->toBe('text-info');
});

test('stat card calculates operators count correctly', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    // Create 3 different operators
    $operators = User::factory()->count(3)->create();

    foreach ($operators as $operator) {
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $operator->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        // Each operator makes 2 contacts
        Contact::factory()->count(2)->create([
            'event_configuration_id' => $eventConfig->id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $operator->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'section_id' => $section->id,
            'is_duplicate' => false,
        ]);
    }

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'operators_count'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('3') // 3 unique operators
        ->and($data['label'])->toBe('Operators')
        ->and($data['icon'])->toBe('phosphor-users')
        ->and($data['color'])->toBe('text-warning');
});

test('stat card caches results for 3 seconds', function () {
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

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // First call - should cache
    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    $data1 = $component->viewData('data');
    expect($data1['value'])->toBe('5');

    // Add more contacts
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // Second call - should return cached value
    $component2 = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    $data2 = $component2->viewData('data');
    expect($data2['value'])->toBe('5'); // Still cached value
});

test('stat card uses IsWidget trait', function () {
    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'tv',
        'widgetId' => 'test-widget-123',
    ]);

    expect($component->get('size'))->toBe('tv')
        ->and($component->get('widgetId'))->toBe('test-widget-123')
        ->and($component->get('config'))->toBe(['metric' => 'qso_count']);
});

test('stat card generates correct cache key', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    $cacheKey = $component->instance()->cacheKey();

    expect($cacheKey)->toBeString()
        ->toContain('dashboard:widget:StatCard')
        ->toContain((string) $event->id);
});

test('stat card handles unknown metric gracefully', function () {
    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'unknown_metric'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('0')
        ->and($data['label'])->toBe('Unknown')
        ->and($data['icon'])->toBe('phosphor-question');
});

test('stat card refreshes live on contact broadcasts for signed-in viewers only', function () {
    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']);
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    expect($component->instance()->getWidgetListeners())->toBe([
        "echo-private:event.{$event->id},.ContactLogged" => 'handleUpdate',
        "echo-private:event.{$event->id},.ContactUpdated" => 'handleUpdate',
        "echo-private:event.{$event->id},.ContactDeleted" => 'handleUpdate',
    ]);

    // Guests (the public TV dashboard) cannot join the private event channel and rely on polling.
    auth()->logout();

    expect($component->instance()->getWidgetListeners())->toBe([]);
});

test('stat card registers no listeners without an active event', function () {
    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'qso_count'],
        'size' => 'normal',
    ]);

    expect($component->instance()->getWidgetListeners())->toBe([]);
});

/**
 * Log one contact in each of the given sections and return the stat card's sections value.
 *
 * @param  list<string>  $codes
 */
function sectionsWorkedStatFor(?EventType $eventType, array $codes): string
{
    $event = Event::factory()->create(array_filter([
        'event_type_id' => $eventType?->id,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]));
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    foreach ($codes as $code) {
        $section = Section::firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'region' => in_array($code, ['DX', 'MX'], true) ? 'DX' : 'VE', 'country' => 'CA', 'is_active' => true]
        );

        Contact::factory()->create([
            'event_configuration_id' => $eventConfig->id,
            'section_id' => $section->id,
            'is_duplicate' => false,
        ]);
    }

    return Livewire::test(StatCard::class, [
        'config' => ['metric' => 'sections_worked'],
        'size' => 'normal',
    ])->viewData('data')['value'];
}

test('stat card counts Yukon as TER and Mexico as DX for ARRL events', function () {
    expect(sectionsWorkedStatFor(null, ['TER', 'YT', 'DX', 'MX']))->toBe('2');
});

test('stat card counts Yukon and Mexico separately for Winter Field Day', function () {
    $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day', 'is_active' => true]);

    expect(sectionsWorkedStatFor($wfd, ['TER', 'YT', 'DX', 'MX']))->toBe('4');
});
