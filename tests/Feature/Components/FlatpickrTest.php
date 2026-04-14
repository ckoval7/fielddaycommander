<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    view()->share('errors', new ViewErrorBag);
});

it('renders with default datetime mode', function () {
    $html = Blade::render('<x-flatpickr wire:model="testField" />');

    expect($html)
        ->toContain('x-data="flatpickr')
        ->toContain("mode: 'datetime'")
        ->toContain('YYYY-MM-DD HH:MM');
});

it('renders with date mode', function () {
    $html = Blade::render('<x-flatpickr mode="date" wire:model="testField" />');

    expect($html)
        ->toContain("mode: 'date'")
        ->toContain('YYYY-MM-DD');
});

it('renders with time mode', function () {
    $html = Blade::render('<x-flatpickr mode="time" wire:model="testField" />');

    expect($html)
        ->toContain("mode: 'time'")
        ->toContain('HH:MM');
});

it('renders label and icon', function () {
    $html = Blade::render('<x-flatpickr label="Start Time" icon="o-clock" wire:model="testField" />');

    expect($html)
        ->toContain('Start Time')
        ->toContain('<svg');
});

it('renders hint text', function () {
    $html = Blade::render('<x-flatpickr hint="Pick a date" wire:model="testField" />');

    expect($html)->toContain('Pick a date');
});

it('forwards wire:model attribute', function () {
    $html = Blade::render('<x-flatpickr wire:model="startTime" />');

    expect($html)->toContain('wire:model="startTime"');
});

it('renders with min and max constraints', function () {
    $html = Blade::render('<x-flatpickr min="2025-01-01" max="2025-12-31" wire:model="testField" />');

    expect($html)
        ->toContain("min: '2025-01-01'")
        ->toContain("max: '2025-12-31'");
});

it('does not render now button by default', function () {
    $html = Blade::render('<x-flatpickr wire:model="testField" />');

    expect($html)->not->toContain('Now');
});

it('renders now button when now-button prop is set', function () {
    $html = Blade::render('<x-flatpickr wire:model="testField" now-button />');

    expect($html)
        ->toContain('Now')
        ->toContain('x-on:click="setNow()"');
});

it('passes nowUtc true by default in x-data when now-button is set', function () {
    $html = Blade::render('<x-flatpickr wire:model="testField" now-button />');

    expect($html)->toContain('nowUtc: true');
});

it('passes nowUtc false when now-utc is false', function () {
    $html = Blade::render('<x-flatpickr wire:model="testField" now-button :now-utc="false" />');

    expect($html)->toContain('nowUtc: false');
});
