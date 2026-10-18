<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Support\BroadcastChannels;

test('builds channel names from ids', function () {
    expect(BroadcastChannels::event(7))->toBe('event.7')
        ->and(BroadcastChannels::user(3))->toBe('user.3')
        ->and(BroadcastChannels::externalLogger(12))->toBe('event.12.external-logger')
        ->and(BroadcastChannels::weather())->toBe('weather');
});

test('builds authorization patterns from route placeholders', function () {
    expect(BroadcastChannels::event('{eventId}'))->toBe('event.{eventId}')
        ->and(BroadcastChannels::user('{id}'))->toBe('user.{id}')
        ->and(BroadcastChannels::externalLogger('{eventConfigId}'))->toBe('event.{eventConfigId}.external-logger');
});

test('builds livewire echo listener keys', function () {
    expect(BroadcastChannels::privateListener(BroadcastChannels::event(7), '.ContactLogged'))
        ->toBe('echo-private:event.7,.ContactLogged')
        ->and(BroadcastChannels::publicListener(BroadcastChannels::weather(), 'WeatherAlertChanged'))
        ->toBe('echo:weather,WeatherAlertChanged');
});

test('builds listeners for every contact change on an event channel', function () {
    expect(BroadcastChannels::contactChangeListeners(7, 'refresh'))->toBe([
        'echo-private:event.7,.ContactLogged' => 'refresh',
        'echo-private:event.7,.ContactUpdated' => 'refresh',
        'echo-private:event.7,.ContactDeleted' => 'refresh',
    ]);
});
