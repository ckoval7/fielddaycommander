<?php

use App\Livewire\Dashboard\Widgets\StatCard;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

use function Pest\Laravel\travelTo;

beforeEach(function () {
    // Clear cache before each test
    Cache::flush();
});

test('stat card calculates avg qso rate 4h correctly with contacts', function () {
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

    // Create 12 contacts in the last 4 hours
    // This should give us an average of 3 QSOs per hour
    Contact::factory()->count(12)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(2), // Within last 4 hours
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'avg_qso_rate_4h'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('3.0') // 12 / 4 = 3.0
        ->and($data['label'])->toBe('Avg QSO Rate (4h)')
        ->and($data['icon'])->toBe('phosphor-chart-bar')
        ->and($data['color'])->toBe('text-info');
});

test('stat card calculates avg qso rate 4h correctly with fractional rate', function () {
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

    // Create 7 contacts - should give us 1.75 per hour
    Contact::factory()->count(7)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(2),
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'avg_qso_rate_4h'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('1.8'); // 7 / 4 = 1.75, formatted to 1 decimal = 1.8
});

test('stat card avg qso rate 4h excludes contacts older than 4 hours', function () {
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

    // Create 8 recent contacts
    Contact::factory()->count(8)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(2),
        'is_duplicate' => false,
    ]);

    // Create 5 old contacts (more than 4 hours ago)
    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(6),
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'avg_qso_rate_4h'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('2.0'); // Only 8 recent contacts / 4 = 2.0
});

test('stat card avg qso rate 4h excludes duplicates', function () {
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

    // Create 10 valid contacts
    Contact::factory()->count(10)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(2),
        'is_duplicate' => false,
    ]);

    // Create 6 duplicate contacts
    Contact::factory()->count(6)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(2),
        'is_duplicate' => true,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'avg_qso_rate_4h'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('2.5'); // Only 10 valid contacts / 4 = 2.5
});

test('stat card calculates contacts last hour correctly', function () {
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

    // Create 5 contacts in the last hour
    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subMinutes(30),
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'contacts_last_hour'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('5')
        ->and($data['label'])->toBe('Contacts Last Hour')
        ->and($data['icon'])->toBe('phosphor-clock')
        ->and($data['color'])->toBe('text-success');
});

test('stat card contacts last hour excludes older contacts', function () {
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

    // Create 3 contacts in the last hour
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subMinutes(45),
        'is_duplicate' => false,
    ]);

    // Create 7 contacts more than 1 hour ago
    Contact::factory()->count(7)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(2),
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'contacts_last_hour'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('3'); // Only recent contacts
});

test('stat card contacts last hour excludes duplicates', function () {
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

    // Create 4 valid contacts
    Contact::factory()->count(4)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subMinutes(30),
        'is_duplicate' => false,
    ]);

    // Create 2 duplicate contacts
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subMinutes(30),
        'is_duplicate' => true,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'contacts_last_hour'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('4'); // Only non-duplicates
});

test('stat card calculates hours remaining correctly for ongoing event', function () {
    // Create an event that ends in 6 hours
    $event = Event::factory()->create([
        'start_time' => now()->subHours(18),
        'end_time' => now()->addHours(6),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'hours_remaining'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('6')
        ->and($data['label'])->toBe('Hours Remaining')
        ->and($data['icon'])->toBe('phosphor-clock')
        ->and($data['color'])->toBe('text-warning');
});

test('stat card hours remaining returns zero for ended event', function () {
    // Create an event that ended 2 hours ago
    $event = Event::factory()->create([
        'start_time' => now()->subHours(26),
        'end_time' => now()->subHours(2),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'hours_remaining'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('0'); // Event ended, should show 0
});

test('stat card hours remaining uses appNow for time travel', function () {
    // Create an event ending in 10 hours from "real now"
    $event = Event::factory()->create([
        'start_time' => now()->subHours(14),
        'end_time' => now()->addHours(10),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Travel forward 5 hours using appNow
    travelTo(now()->addHours(5));

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'hours_remaining'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    // Should now have 5 hours remaining (10 - 5)
    expect($data['value'])->toBe('5');
});

test('stat card calculates bonus points earned correctly', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $bonusType = BonusType::factory()->create();
    $user = User::factory()->create();

    // Create 3 bonus point entries
    EventBonus::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'bonus_type_id' => $bonusType->id,
        'claimed_by_user_id' => $user->id,
        'calculated_points' => 100,
    ]);

    EventBonus::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'bonus_type_id' => $bonusType->id,
        'claimed_by_user_id' => $user->id,
        'calculated_points' => 50,
    ]);

    EventBonus::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'bonus_type_id' => $bonusType->id,
        'claimed_by_user_id' => $user->id,
        'calculated_points' => 25,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'bonus_points_earned'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('175') // 100 + 50 + 25
        ->and($data['label'])->toBe('Bonus Points')
        ->and($data['icon'])->toBe('phosphor-star')
        ->and($data['color'])->toBe('text-accent');
});

test('stat card bonus points earned returns zero when no bonuses exist', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'bonus_points_earned'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('0');
});

test('stat card bonus points earned only counts current event', function () {
    // Create current active event
    $currentEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $currentConfig = EventConfiguration::factory()->create(['event_id' => $currentEvent->id]);

    // Create past event
    $pastEvent = Event::factory()->create([
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);

    $bonusType = BonusType::factory()->create();
    $user = User::factory()->create();

    // Add bonuses to current event
    EventBonus::factory()->create([
        'event_configuration_id' => $currentConfig->id,
        'bonus_type_id' => $bonusType->id,
        'claimed_by_user_id' => $user->id,
        'calculated_points' => 100,
    ]);

    // Add bonuses to past event (should not be counted)
    EventBonus::factory()->create([
        'event_configuration_id' => $pastConfig->id,
        'bonus_type_id' => $bonusType->id,
        'claimed_by_user_id' => $user->id,
        'calculated_points' => 500,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'bonus_points_earned'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('100'); // Only current event bonuses
});

test('stat card calculates guestbook count correctly', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Create 5 guestbook entries for this event
    GuestbookEntry::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'guestbook_count'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('5')
        ->and($data['label'])->toBe('Guestbook Entries')
        ->and($data['icon'])->toBe('phosphor-book-open')
        ->and($data['color'])->toBe('text-info');
});

test('stat card guestbook count only counts current event', function () {
    $currentEvent = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $currentConfig = EventConfiguration::factory()->create(['event_id' => $currentEvent->id]);

    $pastEvent = Event::factory()->create([
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);

    GuestbookEntry::factory()->count(3)->create([
        'event_configuration_id' => $currentConfig->id,
    ]);

    GuestbookEntry::factory()->count(10)->create([
        'event_configuration_id' => $pastConfig->id,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'guestbook_count'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['value'])->toBe('3');
});

test('stat card calculates points per hour from total score and elapsed time', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(4),
        'end_time' => now()->addHours(20),
    ]);
    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'power_multiplier' => 1,
    ]);

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

    Contact::factory()->count(10)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(2),
        'is_duplicate' => false,
        'is_gota_contact' => false,
        'points' => 8,
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'points_per_hour'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['label'])->toBe('Points / Hour')
        ->and($data['icon'])->toBe('phosphor-lightning')
        ->and($data['color'])->toBe('text-success')
        ->and((float) str_replace(',', '', $data['value']))->toBeGreaterThan(0.0);
});

test('stat card counts stations with at least one open operating session', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();

    $activeStationA = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $activeStationB = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $idleStation = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);

    foreach ([$activeStationA, $activeStationB] as $station) {
        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $user->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'start_time' => now()->subHour(),
            'end_time' => null,
        ]);
    }

    OperatingSession::factory()->create([
        'station_id' => $idleStation->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'start_time' => now()->subHours(2),
        'end_time' => now()->subHour(),
    ]);

    $component = Livewire::test(StatCard::class, [
        'config' => ['metric' => 'stations_count'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toBeArray()
        ->and($data['value'])->toBe('2')
        ->and($data['label'])->toBe('Active Stations')
        ->and($data['icon'])->toBe('phosphor-broadcast')
        ->and($data['color'])->toBe('text-warning');
});

test('stat card returns empty metric for new metrics when no active event', function () {
    $metrics = [
        'avg_qso_rate_4h' => ['Avg QSO Rate (4h)', 'phosphor-chart-bar', 'text-info'],
        'contacts_last_hour' => ['Contacts Last Hour', 'phosphor-clock', 'text-success'],
        'hours_remaining' => ['Hours Remaining', 'phosphor-clock', 'text-warning'],
        'bonus_points_earned' => ['Bonus Points', 'phosphor-star', 'text-accent'],
        'guestbook_count' => ['Guestbook Entries', 'phosphor-book-open', 'text-info'],
        'points_per_hour' => ['Points / Hour', 'phosphor-lightning', 'text-success'],
        'stations_count' => ['Active Stations', 'phosphor-broadcast', 'text-warning'],
    ];

    foreach ($metrics as $metricName => [$label, $icon, $color]) {
        $component = Livewire::test(StatCard::class, [
            'config' => ['metric' => $metricName],
            'size' => 'normal',
        ]);

        $data = $component->viewData('data');

        expect($data)
            ->toBeArray()
            ->and($data['value'])->toBe('0')
            ->and($data['label'])->toBe($label)
            ->and($data['icon'])->toBe($icon)
            ->and($data['color'])->toBe($color);
    }
});
