<?php

use App\Livewire\Dashboard\LayoutSelector;
use Livewire\Livewire;

beforeEach(function () {
    config(['dashboard.layouts' => [
        'default' => [
            'name' => 'Default',
            'description' => 'Standard dashboard layout',
        ],
        'compact' => [
            'name' => 'Compact',
            'description' => 'Compact layout',
        ],
        'tv' => [
            'name' => 'TV',
            'description' => 'TV display layout',
        ],
    ]]);
});

test('component renders successfully', function () {
    Livewire::test(LayoutSelector::class)
        ->assertOk();
});

test('mount loads layouts from config', function () {
    $component = Livewire::test(LayoutSelector::class);

    $layouts = $component->get('layouts');

    expect($layouts)->toHaveCount(3);
    expect($layouts[0]['key'])->toBe('default');
    expect($layouts[0]['name'])->toBe('Default');
    expect($layouts[1]['key'])->toBe('compact');
    expect($layouts[2]['key'])->toBe('tv');
});

test('mount uses key as fallback name when name is not set', function () {
    config(['dashboard.layouts' => [
        'custom' => [
            'description' => 'No name provided',
        ],
    ]]);

    $component = Livewire::test(LayoutSelector::class);

    $layouts = $component->get('layouts');

    expect($layouts[0]['name'])->toBe('Custom');
});

test('mount sets empty description when not set in config', function () {
    config(['dashboard.layouts' => [
        'default' => [
            'name' => 'Default',
        ],
    ]]);

    $component = Livewire::test(LayoutSelector::class);

    $layouts = $component->get('layouts');

    expect($layouts[0]['description'])->toBe('');
});

test('mount loads empty layouts when config is empty', function () {
    config(['dashboard.layouts' => []]);

    $component = Livewire::test(LayoutSelector::class);

    expect($component->get('layouts'))->toBeEmpty();
});

test('switchLayout dispatches layout-changed event for non-TV layouts', function () {
    Livewire::test(LayoutSelector::class)
        ->call('switchLayout', 'default')
        ->assertDispatched('layout-changed', layout: 'default');
});

test('switchLayout dispatches layout-changed event for compact layout', function () {
    Livewire::test(LayoutSelector::class)
        ->call('switchLayout', 'compact')
        ->assertDispatched('layout-changed', layout: 'compact');
});

test('switchLayout updates selectedLayout for non-TV layouts', function () {
    Livewire::test(LayoutSelector::class)
        ->call('switchLayout', 'compact')
        ->assertSet('selectedLayout', 'compact');
});

test('switchLayout redirects to TV route for TV layout', function () {
    Livewire::test(LayoutSelector::class)
        ->call('switchLayout', 'tv')
        ->assertRedirect(route('dashboard.tv'));
});

test('switchLayout does not dispatch event for TV layout', function () {
    Livewire::test(LayoutSelector::class)
        ->call('switchLayout', 'tv')
        ->assertNotDispatched('layout-changed');
});

test('switchLayout ignores invalid layout keys', function () {
    Livewire::test(LayoutSelector::class)
        ->call('switchLayout', 'nonexistent')
        ->assertNotDispatched('layout-changed')
        ->assertSet('selectedLayout', 'default');
});

test('switchLayout ignores empty string layout key', function () {
    Livewire::test(LayoutSelector::class)
        ->call('switchLayout', '')
        ->assertNotDispatched('layout-changed')
        ->assertSet('selectedLayout', 'default');
});

test('component has default selectedLayout of default', function () {
    Livewire::test(LayoutSelector::class)
        ->assertSet('selectedLayout', 'default');
});
