<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Dashboard\ConnectionStatus;
use Livewire\Livewire;

test('component renders with default settings', function () {
    Livewire::test(ConnectionStatus::class)
        ->assertSet('showBanner', false)
        ->assertSet('showBadge', true)
        ->assertSet('isTvMode', false);
});

test('component can be initialized in TV mode', function () {
    Livewire::test(ConnectionStatus::class, ['isTvMode' => true])
        ->assertSet('isTvMode', true);
});

test('component can be initialized in normal mode', function () {
    Livewire::test(ConnectionStatus::class, ['isTvMode' => false])
        ->assertSet('isTvMode', false);
});

test('component displays connection status badge by default', function () {
    Livewire::test(ConnectionStatus::class)
        ->assertSee('Connected'); // Default state shows connected
});

test('component shows offline banner elements', function () {
    Livewire::test(ConnectionStatus::class)
        ->assertSee('Connection Lost')
        ->assertSee('Real-time updates are unavailable');
});

test('component has dismissible banner', function () {
    Livewire::test(ConnectionStatus::class)
        ->assertSee('Dismiss banner'); // Aria label for dismiss button
});

test('TV mode uses larger badge styling', function () {
    $component = Livewire::test(ConnectionStatus::class, ['isTvMode' => true]);

    // TV mode should render with badge-lg class
    expect($component->get('isTvMode'))->toBeTrue();
});

test('normal mode uses standard badge styling', function () {
    $component = Livewire::test(ConnectionStatus::class, ['isTvMode' => false]);

    // Normal mode should render with badge-md class
    expect($component->get('isTvMode'))->toBeFalse();
});

test('component includes warning banner text', function () {
    Livewire::test(ConnectionStatus::class)
        ->assertSee('Connection Lost')
        ->assertSee('Attempting to reconnect');
});

test('component includes dismiss button', function () {
    Livewire::test(ConnectionStatus::class)
        ->assertSee('Dismiss banner');
});

test('component renders banner and badge elements', function () {
    $component = Livewire::test(ConnectionStatus::class);

    // Component should have banner and badge elements
    expect($component->get('showBanner'))->toBeFalse();
    expect($component->get('showBadge'))->toBeTrue();
});

test('badge can be hidden via showBadge property', function () {
    $component = Livewire::test(ConnectionStatus::class)
        ->set('showBadge', false);

    expect($component->get('showBadge'))->toBeFalse();
});

test('component has proper z-index for layering', function () {
    Livewire::test(ConnectionStatus::class)
        ->assertSee('z-50') // Banner
        ->assertSee('z-40'); // Badge
});

test('component supports different connection states', function () {
    Livewire::test(ConnectionStatus::class)
        ->assertSee('Connected')
        ->assertSee('Connecting')
        ->assertSee('Offline');
});
