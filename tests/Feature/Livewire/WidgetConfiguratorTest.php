<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Dashboard\WidgetConfigurator;
use Livewire\Livewire;

test('component can mount without widget type', function () {
    Livewire::test(WidgetConfigurator::class)
        ->assertSet('widgetType', '')
        ->assertSet('widgetConfig', [])
        ->assertSet('showModal', false)
        ->assertSet('mode', 'add');
});

test('component can mount with widget type and config', function () {
    Livewire::test(WidgetConfigurator::class, [
        'widgetType' => 'stat_card',
        'config' => ['metric' => 'total_score', 'show_trend' => true],
    ])
        ->assertSet('widgetType', 'stat_card')
        ->assertSet('widgetConfig.metric', 'total_score')
        ->assertSet('widgetConfig.show_trend', true);
});

test('component loads schema when widget type is set', function () {
    $component = Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'stat_card')
        ->assertSet('widgetType', 'stat_card');

    // Verify schema is loaded by checking that config is initialized with defaults
    expect($component->get('widgetConfig'))->toBeArray()->not->toBeEmpty();
});

test('component initializes config with schema defaults', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'stat_card')
        ->assertSet('widgetConfig.metric', 'total_score')
        ->assertSet('widgetConfig.show_trend', true);
});

test('component renders widget type selector in add mode', function () {
    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->set('mode', 'add')
        ->assertSee('Widget Type')
        ->assertSee('Select a widget type');
});

test('component does not render widget type selector in edit mode', function () {
    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->set('mode', 'edit')
        ->set('widgetType', 'stat_card')
        ->assertDontSee('Widget Type')
        ->assertDontSee('Select a widget type');
});

test('component renders select field for select type schema', function () {
    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->call('setWidgetType', 'stat_card')
        ->assertSee('Metric')
        ->assertSee('Total Score')
        ->assertSee('QSO Count');
});

test('component renders toggle field for toggle type schema', function () {
    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->call('setWidgetType', 'stat_card')
        ->assertSee('Show Trend Indicator');
});

test('component renders number field for number type schema', function () {
    config(['dashboard.widget_types.test_widget' => [
        'name' => 'Test Widget',
        'config_schema' => [
            'target' => ['type' => 'number', 'label' => 'Custom Target', 'min' => 0, 'max' => 100],
        ],
    ]]);

    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->call('setWidgetType', 'test_widget')
        ->assertSee('Custom Target');
});

test('component renders text field for text type schema', function () {
    // First, temporarily add a text field to the config
    config(['dashboard.widget_types.test_widget' => [
        'name' => 'Test Widget',
        'config_schema' => [
            'text_field' => [
                'type' => 'text',
                'label' => 'Test Text Field',
            ],
        ],
    ]]);

    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->call('setWidgetType', 'test_widget')
        ->assertSee('Test Text Field');
});

test('component validates required fields', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'stat_card')
        ->set('widgetConfig.metric', '')
        ->call('save')
        ->assertHasErrors(['widgetConfig.metric' => 'required']);
});

test('component validates select field values', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'stat_card')
        ->set('widgetConfig.metric', 'invalid_metric')
        ->call('save')
        ->assertHasErrors(['widgetConfig.metric' => 'in']);
});

test('component validates number field type', function () {
    config(['dashboard.widget_types.test_widget' => [
        'name' => 'Test Widget',
        'config_schema' => [
            'target' => ['type' => 'number', 'label' => 'Custom Target', 'min' => 0, 'max' => 100],
        ],
    ]]);

    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'test_widget')
        ->set('widgetConfig.target', 'not-a-number')
        ->call('save')
        ->assertHasErrors(['widgetConfig.target' => 'numeric']);
});

test('component validates number field minimum value', function () {
    config(['dashboard.widget_types.test_widget' => [
        'name' => 'Test Widget',
        'config_schema' => [
            'target' => ['type' => 'number', 'label' => 'Custom Target', 'min' => 0, 'max' => 100],
        ],
    ]]);

    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'test_widget')
        ->set('widgetConfig.target', -1)
        ->call('save')
        ->assertHasErrors(['widgetConfig.target' => 'min']);
});

test('component validates toggle field as boolean', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'stat_card')
        ->set('widgetConfig.show_trend', 'not-a-boolean')
        ->call('save')
        ->assertHasErrors(['widgetConfig.show_trend' => 'boolean']);
});

test('component emits widget-configured event on save with valid data', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'stat_card')
        ->set('widgetConfig.metric', 'qso_count')
        ->set('widgetConfig.show_trend', false)
        ->call('save')
        ->assertDispatched('widget-configured', function ($event, $params) {
            return $params['type'] === 'stat_card'
                && $params['config']['metric'] === 'qso_count'
                && $params['config']['show_trend'] === false
                && $params['mode'] === 'add';
        });
});

test('component closes modal and resets state on save', function () {
    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->call('setWidgetType', 'stat_card')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertSet('widgetType', '')
        ->assertSet('widgetConfig', [])
        ->assertSet('mode', 'add');
});

test('component closes modal and resets state on cancel', function () {
    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->call('setWidgetType', 'stat_card')
        ->set('widgetConfig.metric', 'qso_count')
        ->call('cancel')
        ->assertSet('showModal', false)
        ->assertSet('widgetType', '')
        ->assertSet('widgetConfig', [])
        ->assertSet('mode', 'add');
});

test('component opens modal in add mode', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('openModal', 'add')
        ->assertSet('showModal', true)
        ->assertSet('mode', 'add');
});

test('component opens modal in edit mode with widget type and config', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('openModal', 'edit', 'stat_card', ['metric' => 'sections_worked', 'show_trend' => false])
        ->assertSet('showModal', true)
        ->assertSet('mode', 'edit')
        ->assertSet('widgetType', 'stat_card')
        ->assertSet('widgetConfig.metric', 'sections_worked')
        ->assertSet('widgetConfig.show_trend', false);
});

test('component returns available widget types', function () {
    $component = Livewire::test(WidgetConfigurator::class);

    $availableTypes = $component->get('availableWidgetTypes');

    expect($availableTypes)->toBeArray()->not->toBeEmpty();
    expect($availableTypes)->toHaveKey('stat_card');
    expect($availableTypes['stat_card'])->toHaveKeys(['value', 'label', 'description', 'icon']);
    expect($availableTypes['stat_card']['value'])->toBe('stat_card');
    expect($availableTypes['stat_card']['label'])->toBe('Stat Card');
});

test('component preserves existing config when merging with defaults', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'stat_card')
        ->set('widgetConfig.metric', 'qso_count')
        ->call('setWidgetType', 'stat_card')
        ->assertSet('widgetConfig.metric', 'qso_count')
        ->assertSet('widgetConfig.show_trend', true);
});

test('component handles multiple select fields', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'chart')
        ->assertSee('Chart Type')
        ->assertSee('Data Source')
        ->assertSee('Time Range');
});

test('component validates all fields in complex schema', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'chart')
        ->set('widgetConfig.chart_type', '')
        ->set('widgetConfig.data_source', '')
        ->call('save')
        ->assertHasErrors([
            'widgetConfig.chart_type' => 'required',
            'widgetConfig.data_source' => 'required',
        ]);
});

test('component saves valid complex configuration', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'chart')
        ->set('widgetConfig.chart_type', 'line')
        ->set('widgetConfig.data_source', 'qsos_per_band')
        ->set('widgetConfig.time_range', 'last_hour')
        ->call('save')
        ->assertDispatched('widget-configured', function ($event, $params) {
            return $params['type'] === 'chart'
                && $params['config']['chart_type'] === 'line'
                && $params['config']['data_source'] === 'qsos_per_band'
                && $params['config']['time_range'] === 'last_hour';
        });
});

test('component displays widget description in add mode', function () {
    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->set('mode', 'add')
        ->call('setWidgetType', 'stat_card')
        ->assertSee('Display a single metric as a large number with optional trend indicator');
});

test('component shows save button as disabled when no widget type selected', function () {
    Livewire::test(WidgetConfigurator::class)
        ->set('showModal', true)
        ->set('mode', 'add')
        ->assertSee('disabled');
});

test('component handles nullable optional fields', function () {
    Livewire::test(WidgetConfigurator::class)
        ->call('setWidgetType', 'progress_bar')
        ->set('widgetConfig.metric', 'next_milestone')
        ->set('widgetConfig.show_percentage', true)
        ->set('widgetConfig.custom_target', null)
        ->call('save')
        ->assertHasNoErrors();
});
