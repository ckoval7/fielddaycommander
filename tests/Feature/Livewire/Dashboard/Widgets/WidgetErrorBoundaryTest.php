<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Dashboard\Widgets\BandModeGrid;
use App\Models\Band;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
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

test('BandModeGrid pads band columns and pins the mode column for narrow screens', function () {
    Mode::factory()->create(['name' => 'CW']);
    Band::factory()->create(['name' => '20m', 'frequency_mhz' => 14, 'allowed_fd' => true]);

    Livewire::test(BandModeGrid::class)
        ->assertSeeHtml('px-1.5 whitespace-nowrap')
        ->assertSeeHtml('sticky left-0 z-10 bg-base-100');
});
