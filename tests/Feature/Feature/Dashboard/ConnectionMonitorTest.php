<?php

use function Pest\Laravel\get;

it('renders connection monitor component', function () {
    $html = view('components.dashboard.connection-monitor')->render();

    expect($html)->toContain('Real-time updates paused');
    expect($html)->toContain('Reconnecting');
    expect($html)->toContain('Real-time updates restored');
});

it('connection monitor has dismissible banner', function () {
    $html = view('components.dashboard.connection-monitor')->render();

    expect($html)->toContain('dismissBanner');
    expect($html)->toContain('Dismiss');
});

it('connection monitor includes WebSocket detection logic', function () {
    $html = view('components.dashboard.connection-monitor')->render();

    expect($html)->toContain('checkConnection');
    expect($html)->toContain('window.Echo');
    expect($html)->toContain('handleReconnect');
    expect($html)->toContain('handleDisconnect');
});

it('connection monitor hides banner in TV mode', function () {
    $html = view('components.dashboard.connection-monitor', ['tvMode' => true])->render();

    // Should still have the Alpine logic but with tvMode check
    expect($html)->toContain('x-show="showBanner && !');
});

it('connection monitor shows banner in normal mode', function () {
    $html = view('components.dashboard.connection-monitor', ['tvMode' => false])->render();

    expect($html)->toContain('Real-time updates paused');
});

it('connection monitor uses appropriate transitions', function () {
    $html = view('components.dashboard.connection-monitor')->render();

    expect($html)->toContain('x-transition');
    expect($html)->toContain('transition ease-out');
});

it('connection monitor dispatches connection events', function () {
    $html = view('components.dashboard.connection-monitor')->render();

    expect($html)->toContain('connection-lost');
    expect($html)->toContain('connection-restored');
    expect($html)->toContain('window.dispatchEvent');
});
