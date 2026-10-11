<?php

use App\Livewire\Dashboard\Widgets\StatCard;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Seed a comparison sample recorded the given number of minutes ago.
 */
function seedComparisonSample(string $historicalKey, float $value, int $minutesAgo): void
{
    $recordedAt = appNow()->subMinutes($minutesAgo)->getTimestamp();

    Cache::put($historicalKey, [$recordedAt - ($recordedAt % 60) => $value], now()->addHours(5));
}

beforeEach(function () {
    // Create active event for testing
    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    $this->user = User::factory()->create();
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
    ]);
    $this->session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
    ]);
    $this->band = Band::firstOrCreate(
        ['name' => '20m'],
        Band::factory()->make()->toArray()
    );
    $this->mode = Mode::firstOrCreate(
        ['name' => 'SSB'],
        Mode::factory()->make()->toArray()
    );
});

it('returns data without comparison on first load', function () {
    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)
        ->toHaveKey('value')
        ->toHaveKey('label')
        ->and($data)->not->toHaveKey('trend');
});

it('calculates comparison data with previous value', function () {
    // Create initial contacts
    Contact::factory()->count(10)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    // First load - store initial value
    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    $firstData = $component->viewData('data');
    expect($firstData)->not->toHaveKey('trend');

    // Create more contacts
    Contact::factory()->count(5)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    // Reloading straight away must not compare against the sample written
    // moments ago - that was the old "vs 1h ago really means vs last poll" bug.
    $this->travel(5)->seconds();
    $immediate = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);
    expect($immediate->viewData('data'))->not->toHaveKey('trend');

    $this->travel(61)->minutes();

    // Second load - should show comparison
    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    $secondData = $component->viewData('data');

    expect($secondData)
        ->toHaveKey('trend')
        ->toHaveKey('change_amount')
        ->toHaveKey('change_percentage')
        ->toHaveKey('comparison_label')
        ->and($secondData['trend'])->toBe('up')
        ->and($secondData['change_amount'])->toBe(5.0)
        ->and($secondData['comparison_label'])->toBe('vs 1h ago');
});

it('calculates trend as up when value increases', function () {
    // Store initial value in cache
    $configHash = hash('xxh128', json_encode([
        'metric' => 'qso_count',
        'show_comparison' => true,
        'comparison_interval' => '1h',
    ]));
    $historicalKey = "dashboard:widget:StatCard:{$configHash}:{$this->eventConfig->id}:history:1h";
    seedComparisonSample($historicalKey, 10, 61);

    // Create more contacts (15 total)
    Contact::factory()->count(15)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['trend'])->toBe('up')
        ->and($data['change_amount'])->toBe(5.0);
});

it('calculates trend as down when value decreases', function () {
    // Store initial value in cache (higher than current)
    $configHash = hash('xxh128', json_encode([
        'metric' => 'qso_count',
        'show_comparison' => true,
        'comparison_interval' => '1h',
    ]));
    $historicalKey = "dashboard:widget:StatCard:{$configHash}:{$this->eventConfig->id}:history:1h";
    seedComparisonSample($historicalKey, 20, 61);

    // Create fewer contacts (10 total)
    Contact::factory()->count(10)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['trend'])->toBe('down')
        ->and($data['change_amount'])->toBe(-10.0);
});

it('calculates trend as stable when value unchanged', function () {
    // Store initial value in cache
    $configHash = hash('xxh128', json_encode([
        'metric' => 'qso_count',
        'show_comparison' => true,
        'comparison_interval' => '1h',
    ]));
    $historicalKey = "dashboard:widget:StatCard:{$configHash}:{$this->eventConfig->id}:history:1h";
    seedComparisonSample($historicalKey, 10, 61);

    // Create same number of contacts
    Contact::factory()->count(10)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['trend'])->toBe('stable')
        ->and($data['change_amount'])->toBe(0.0);
});

it('supports different comparison intervals', function () {
    // Store value in 4h cache
    $configHash = hash('xxh128', json_encode([
        'metric' => 'qso_count',
        'show_comparison' => true,
        'comparison_interval' => '4h',
    ]));
    $historicalKey = "dashboard:widget:StatCard:{$configHash}:{$this->eventConfig->id}:history:4h";
    seedComparisonSample($historicalKey, 5, 241);

    Contact::factory()->count(10)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    // Clear component cache
    Cache::forget('dashboard:widget:StatCard:'.$configHash.':'.$this->eventConfig->id);

    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '4h',
        ],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['comparison_label'])->toBe('vs 4h ago')
        ->and($data['change_amount'])->toBe(5.0);
});

it('does not show comparison when disabled', function () {
    Contact::factory()->count(10)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => false,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data)->not->toHaveKey('trend')
        ->and($data)->not->toHaveKey('change_amount');
});

it('generates correct cache keys for different intervals', function () {
    $configHash = hash('xxh128', json_encode([
        'metric' => 'qso_count',
        'show_comparison' => true,
        'comparison_interval' => '1h',
    ]));

    $expectedKey = "dashboard:widget:StatCard:{$configHash}:{$this->eventConfig->id}:history:1h";

    // First load stores value
    Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    expect(Cache::has($expectedKey))->toBeTrue();
});

it('handles formatted numbers correctly', function () {
    // Create a large number of contacts to ensure formatting
    Contact::factory()->count(1500)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
    ]);

    // Store previous value
    $configHash = hash('xxh128', json_encode([
        'metric' => 'qso_count',
        'show_comparison' => true,
        'comparison_interval' => '1h',
    ]));
    $historicalKey = "dashboard:widget:StatCard:{$configHash}:{$this->eventConfig->id}:history:1h";
    seedComparisonSample($historicalKey, 1000, 61);

    $component = Livewire::test(StatCard::class, [
        'widgetId' => 'stat-card-1',
        'config' => [
            'metric' => 'qso_count',
            'show_comparison' => true,
            'comparison_interval' => '1h',
        ],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    // Value should be formatted with comma
    expect($data['value'])->toBe('1,500')
        // But change_amount should be numeric
        ->and($data['change_amount'])->toBe(500.0)
        ->and($data['trend'])->toBe('up');
});
