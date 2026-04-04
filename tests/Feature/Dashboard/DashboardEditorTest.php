<?php

use App\Livewire\Dashboard\DashboardEditor;
use App\Models\Dashboard;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->dashboard = Dashboard::factory()
        ->for($this->user)
        ->default()
        ->create([
            'title' => 'Test Dashboard',
            'description' => 'Test description',
            'config' => [
                [
                    'id' => 'widget-1',
                    'type' => 'stat_card',
                    'config' => ['metric' => 'total_score'],
                    'order' => 0,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-2',
                    'type' => 'timer',
                    'config' => ['timer_type' => 'event_countdown'],
                    'order' => 1,
                    'visible' => true,
                ],
                [
                    'id' => 'widget-3',
                    'type' => 'chart',
                    'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
                    'order' => 2,
                    'visible' => true,
                ],
            ],
        ]);
});

// ── Component Rendering ──────────────────────────────────────────────

test('component renders successfully', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->assertOk()
        ->assertViewIs('livewire.dashboard.dashboard-editor');
});

test('component displays dashboard title in edit mode', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->assertSet('title', 'Test Dashboard')
        ->assertSet('editMode', true);
});

test('component displays dashboard description in edit mode', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->assertSet('description', 'Test description')
        ->assertSet('editMode', true);
});

test('component loads widgets from dashboard config', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->assertCount('widgets', 3);
});

test('component handles empty config', function () {
    $dashboard = Dashboard::factory()
        ->for($this->user)
        ->create(['config' => []]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->call('enterEditMode')
        ->assertCount('widgets', 0)
        ->assertSee('No widgets configured');
});

test('component handles config with widgets key', function () {
    $dashboard = Dashboard::factory()
        ->for($this->user)
        ->create([
            'config' => [
                'widgets' => [
                    [
                        'id' => 'w1',
                        'type' => 'stat_card',
                        'config' => ['metric' => 'total_score'],
                        'order' => 0,
                        'visible' => true,
                    ],
                ],
            ],
        ]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $dashboard])
        ->assertCount('widgets', 1);
});

// ── Edit Mode Toggle ─────────────────────────────────────────────────

test('edit mode starts disabled', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->assertSet('editMode', false);
});

test('can toggle edit mode on', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('toggleEditMode')
        ->assertSet('editMode', true)
        ->assertSee('Save')
        ->assertSee('Cancel');
});

test('can toggle edit mode off', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('toggleEditMode')
        ->assertSet('editMode', true)
        ->call('toggleEditMode')
        ->assertSet('editMode', false);
});

test('enter edit mode stores original widgets snapshot', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode');

    expect($component->get('originalWidgets'))->toHaveCount(3);
    expect($component->get('editMode'))->toBeTrue();
});

// ── Save Layout ──────────────────────────────────────────────────────

test('save layout persists config to database', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('saveLayout')
        ->assertSet('editMode', false)
        ->assertDispatched('toast');

    $this->dashboard->refresh();
    expect($this->dashboard->config)->toHaveCount(3);
});

test('save layout exits edit mode', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('saveLayout')
        ->assertSet('editMode', false)
        ->assertSet('showDeleteConfirmation', false)
        ->assertSet('widgetToDelete', null);
});

test('save layout dispatches success toast', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('saveLayout')
        ->assertDispatched('toast');
});

// ── Cancel Edit ──────────────────────────────────────────────────────

test('cancel edit restores original widgets', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode');

    $originalCount = $component->get('widgets')->count();

    $component
        ->call('toggleVisibility', 'widget-1')
        ->call('cancelEdit')
        ->assertSet('editMode', false);

    $restoredWidgets = $component->get('widgets');
    expect($restoredWidgets)->toHaveCount($originalCount);

    $widget1 = $restoredWidgets->firstWhere('id', 'widget-1');
    expect($widget1['visible'])->toBeTrue();
});

test('cancel edit dispatches warning toast', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('cancelEdit')
        ->assertDispatched('toast');
});

// ── Reorder Widgets ──────────────────────────────────────────────────

test('reorder widgets updates widget order', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('reorderWidgets', ['widget-3', 'widget-1', 'widget-2']);

    $widgets = $component->get('widgets');
    expect($widgets[0]['id'])->toBe('widget-3');
    expect($widgets[0]['order'])->toBe(0);
    expect($widgets[1]['id'])->toBe('widget-1');
    expect($widgets[1]['order'])->toBe(1);
    expect($widgets[2]['id'])->toBe('widget-2');
    expect($widgets[2]['order'])->toBe(2);
});

test('reorder widgets does nothing when not in edit mode', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('reorderWidgets', ['widget-3', 'widget-1', 'widget-2']);

    $widgets = $component->get('widgets');
    expect($widgets[0]['id'])->toBe('widget-1');
});

test('reorder widgets ignores unknown widget ids', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('reorderWidgets', ['widget-1', 'unknown-widget', 'widget-2']);

    $widgets = $component->get('widgets');
    expect($widgets)->toHaveCount(2);
});

// ── Toggle Visibility ────────────────────────────────────────────────

test('toggle visibility hides a visible widget', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('toggleVisibility', 'widget-1');

    $widget = $component->get('widgets')->firstWhere('id', 'widget-1');
    expect($widget['visible'])->toBeFalse();
});

test('toggle visibility shows a hidden widget', function () {
    $this->dashboard->update([
        'config' => [
            [
                'id' => 'widget-1',
                'type' => 'stat_card',
                'config' => ['metric' => 'total_score'],
                'order' => 0,
                'visible' => false,
            ],
        ],
    ]);

    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard->fresh()])
        ->call('enterEditMode')
        ->call('toggleVisibility', 'widget-1');

    $widget = $component->get('widgets')->firstWhere('id', 'widget-1');
    expect($widget['visible'])->toBeTrue();
});

test('toggle visibility does nothing when not in edit mode', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('toggleVisibility', 'widget-1');

    $widget = $component->get('widgets')->firstWhere('id', 'widget-1');
    expect($widget['visible'])->toBeTrue();
});

// ── Remove Widget ────────────────────────────────────────────────────

test('confirm remove widget sets up delete confirmation', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('confirmRemoveWidget', 'widget-1')
        ->assertSet('widgetToDelete', 'widget-1')
        ->assertSet('showDeleteConfirmation', true);
});

test('cancel remove widget clears delete state', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('confirmRemoveWidget', 'widget-1')
        ->call('cancelRemoveWidget')
        ->assertSet('widgetToDelete', null)
        ->assertSet('showDeleteConfirmation', false);
});

test('remove widget removes widget from collection', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('confirmRemoveWidget', 'widget-2')
        ->call('removeWidget');

    $widgets = $component->get('widgets');
    expect($widgets)->toHaveCount(2);
    expect($widgets->pluck('id')->toArray())->not->toContain('widget-2');
});

test('remove widget reindexes order', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('confirmRemoveWidget', 'widget-1')
        ->call('removeWidget');

    $widgets = $component->get('widgets');
    expect($widgets[0]['order'])->toBe(0);
    expect($widgets[1]['order'])->toBe(1);
});

test('remove widget dispatches toast', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('confirmRemoveWidget', 'widget-1')
        ->call('removeWidget')
        ->assertDispatched('toast');
});

test('remove widget does nothing when not in edit mode', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->set('widgetToDelete', 'widget-1')
        ->call('removeWidget')
        ->assertCount('widgets', 3);
});

test('remove widget does nothing without widget to delete', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('removeWidget')
        ->assertCount('widgets', 3);
});

// ── Add Widget ───────────────────────────────────────────────────────

test('add widget appends new widget', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('addWidget', 'stat_card', ['metric' => 'qso_count']);

    $widgets = $component->get('widgets');
    expect($widgets)->toHaveCount(4);

    $newWidget = $widgets->last();
    expect($newWidget['type'])->toBe('stat_card');
    expect($newWidget['config'])->toBe(['metric' => 'qso_count']);
    expect($newWidget['visible'])->toBeTrue();
    expect($newWidget['order'])->toBe(3);
    expect($newWidget['id'])->toStartWith('stat_card-');
});

test('add widget does nothing when not in edit mode', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('addWidget', 'stat_card', ['metric' => 'qso_count'])
        ->assertCount('widgets', 3);
});

test('add widget respects max widget limit', function () {
    $widgets = [];
    for ($i = 0; $i < 20; $i++) {
        $widgets[] = [
            'id' => "widget-{$i}",
            'type' => 'stat_card',
            'config' => ['metric' => 'total_score'],
            'order' => $i,
            'visible' => true,
        ];
    }

    $this->dashboard->update(['config' => $widgets]);

    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard->fresh()])
        ->call('enterEditMode')
        ->call('addWidget', 'timer', ['timer_type' => 'event_countdown'])
        ->assertCount('widgets', 20)
        ->assertDispatched('toast');
});

test('add widget dispatches success toast', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('addWidget', 'timer', ['timer_type' => 'event_countdown'])
        ->assertDispatched('toast');
});

// ── Widget Configurator Integration ──────────────────────────────────

test('open widget picker dispatches event', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('openWidgetPicker')
        ->assertSet('configuringWidgetId', null)
        ->assertDispatched('open-widget-configurator', mode: 'add');
});

test('configure widget dispatches event with widget data', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('configureWidget', 'widget-1')
        ->assertSet('configuringWidgetId', 'widget-1')
        ->assertDispatched('open-widget-configurator',
            mode: 'edit',
            widgetType: 'stat_card',
            config: ['metric' => 'total_score']
        );
});

test('configure widget does nothing when not in edit mode', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('configureWidget', 'widget-1')
        ->assertSet('configuringWidgetId', null)
        ->assertNotDispatched('open-widget-configurator');
});

test('configure widget does nothing for unknown widget', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('configureWidget', 'nonexistent-widget')
        ->assertSet('configuringWidgetId', null)
        ->assertNotDispatched('open-widget-configurator');
});

test('handle widget configured adds widget in add mode', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->dispatch('widget-configured',
            type: 'progress_bar',
            config: ['metric' => 'next_milestone', 'show_percentage' => true],
            mode: 'add'
        );

    $widgets = $component->get('widgets');
    expect($widgets)->toHaveCount(4);
    expect($widgets->last()['type'])->toBe('progress_bar');
});

test('handle widget configured updates widget in edit mode', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->set('configuringWidgetId', 'widget-1')
        ->dispatch('widget-configured',
            type: 'stat_card',
            config: ['metric' => 'qso_count', 'show_trend' => false],
            mode: 'edit'
        );

    $widgets = $component->get('widgets');
    $updatedWidget = $widgets->firstWhere('id', 'widget-1');
    expect($updatedWidget['config'])->toBe(['metric' => 'qso_count', 'show_trend' => false]);
    expect($component->get('configuringWidgetId'))->toBeNull();
});

test('handle widget configured dispatches toast on edit', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->set('configuringWidgetId', 'widget-1')
        ->dispatch('widget-configured',
            type: 'stat_card',
            config: ['metric' => 'qso_count'],
            mode: 'edit'
        )
        ->assertDispatched('toast');
});

// ── Computed Properties ──────────────────────────────────────────────

test('widget ids computed property returns ordered ids', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard]);

    expect($component->get('widgetIds'))->toBe(['widget-1', 'widget-2', 'widget-3']);
});

// ── Helper Methods ───────────────────────────────────────────────────

test('get widget type label returns configured name', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard]);

    expect($component->instance()->getWidgetTypeLabel('stat_card'))->toBe('Stat Card');
    expect($component->instance()->getWidgetTypeLabel('chart'))->toBe('Chart');
    expect($component->instance()->getWidgetTypeLabel('timer'))->toBe('Timer');
});

test('get widget type icon returns configured icon', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard]);

    expect($component->instance()->getWidgetTypeIcon('stat_card'))->toBe('o-chart-bar');
    expect($component->instance()->getWidgetTypeIcon('chart'))->toBe('o-chart-pie');
});

test('get widget type label falls back for unknown type', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard]);

    expect($component->instance()->getWidgetTypeLabel('unknown_type'))->toBe('Unknown_type');
});

test('get widget type icon falls back for unknown type', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard]);

    expect($component->instance()->getWidgetTypeIcon('unknown_type'))->toBe('o-cube');
});

// ── Full Workflow Integration ────────────────────────────────────────

test('full edit workflow: enter, modify, save', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('toggleVisibility', 'widget-2')
        ->call('reorderWidgets', ['widget-3', 'widget-1', 'widget-2'])
        ->call('saveLayout');

    expect($component->get('editMode'))->toBeFalse();

    $this->dashboard->refresh();
    $config = $this->dashboard->config;

    expect($config[0]['id'])->toBe('widget-3');
    expect($config[1]['id'])->toBe('widget-1');
    expect($config[2]['id'])->toBe('widget-2');
    expect($config[2]['visible'])->toBeFalse();
});

test('full edit workflow: enter, modify, cancel restores original', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('toggleVisibility', 'widget-1')
        ->call('confirmRemoveWidget', 'widget-3')
        ->call('removeWidget')
        ->call('cancelEdit');

    expect($component->get('editMode'))->toBeFalse();

    $widgets = $component->get('widgets');
    expect($widgets)->toHaveCount(3);

    $widget1 = $widgets->firstWhere('id', 'widget-1');
    expect($widget1['visible'])->toBeTrue();
});

test('full edit workflow: add widget and save', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('addWidget', 'progress_bar', ['metric' => 'next_milestone', 'show_percentage' => true])
        ->call('saveLayout');

    $this->dashboard->refresh();
    expect($this->dashboard->config)->toHaveCount(4);

    $lastWidget = collect($this->dashboard->config)->last();
    expect($lastWidget['type'])->toBe('progress_bar');
    expect($lastWidget['config']['metric'])->toBe('next_milestone');
});

// ── Resize Widget ───────────────────────────────────────────────────

test('resize widget updates col_span and row_span', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('resizeWidget', 'widget-1', 2, 1);

    $widget = $component->get('widgets')->firstWhere('id', 'widget-1');
    expect($widget['col_span'])->toBe(2);
    expect($widget['row_span'])->toBe(1);
});

test('resize widget does nothing when not in edit mode', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('resizeWidget', 'widget-1', 2, 2);

    $widget = $component->get('widgets')->firstWhere('id', 'widget-1');
    expect($widget)->not->toHaveKey('col_span');
});

test('resize widget clamps col_span to valid range', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('resizeWidget', 'widget-1', 5, 1);

    $widget = $component->get('widgets')->firstWhere('id', 'widget-1');
    expect($widget['col_span'])->toBe(4);
});

test('resize widget clamps row_span to valid range', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('resizeWidget', 'widget-1', 1, 4);

    $widget = $component->get('widgets')->firstWhere('id', 'widget-1');
    expect($widget['row_span'])->toBe(3);
});

test('resize widget enforces minimum of 1', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('resizeWidget', 'widget-1', 0, 0);

    $widget = $component->get('widgets')->firstWhere('id', 'widget-1');
    expect($widget['col_span'])->toBe(1);
    expect($widget['row_span'])->toBe(1);
});

test('resize widget ignores unknown widget id', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('resizeWidget', 'nonexistent', 2, 2)
        ->assertCount('widgets', 3);
});

test('resize widget persists after save', function () {
    Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('resizeWidget', 'widget-1', 2, 2)
        ->call('saveLayout');

    $this->dashboard->refresh();
    $widget = collect($this->dashboard->config)->firstWhere('id', 'widget-1');
    expect($widget['col_span'])->toBe(2);
    expect($widget['row_span'])->toBe(2);
});

test('remove widget then save persists reduced config', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])
        ->call('enterEditMode')
        ->call('confirmRemoveWidget', 'widget-2')
        ->call('removeWidget')
        ->call('saveLayout');

    $this->dashboard->refresh();
    expect($this->dashboard->config)->toHaveCount(2);
    expect(collect($this->dashboard->config)->pluck('id')->toArray())->not->toContain('widget-2');
});
