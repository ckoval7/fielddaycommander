<?php

use App\Livewire\Dashboard\Widgets\BandModeGrid;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day'],
    );

    $this->event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);
});

test('BandModeGrid widget has error boundary trait', function () {
    $component = Livewire::test(BandModeGrid::class);

    $component->assertSet('hasError', false);
    $component->assertSet('errorMessage', '');
});

test('error fallback view renders with widget name', function () {
    $view = view('livewire.dashboard.widgets.error-fallback', [
        'widgetName' => 'Test Widget',
        'errorMessage' => 'Something went wrong',
    ])->render();

    expect($view)->toContain('Test Widget');
    expect($view)->toContain('Failed to load widget');
});

test('error fallback shows error message in debug mode', function () {
    config(['app.debug' => true]);

    $view = view('livewire.dashboard.widgets.error-fallback', [
        'widgetName' => 'Test Widget',
        'errorMessage' => 'Database connection failed',
    ])->render();

    expect($view)->toContain('Database connection failed');
});

test('error fallback hides error message in production', function () {
    config(['app.debug' => false]);

    $view = view('livewire.dashboard.widgets.error-fallback', [
        'widgetName' => 'Test Widget',
        'errorMessage' => 'Database connection failed',
    ])->render();

    expect($view)->not->toContain('Database connection failed');
    expect($view)->toContain('Failed to load widget');
});
