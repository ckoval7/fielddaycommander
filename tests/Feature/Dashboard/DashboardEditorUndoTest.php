<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Dashboard\DashboardEditor;
use App\Models\Dashboard;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->dashboard = Dashboard::factory()
        ->for($this->user)
        ->default()
        ->create([
            'config' => [
                ['id' => 'a', 'type' => 'stat_card', 'config' => ['metric' => 'qso_count'], 'order' => 0, 'visible' => true],
                ['id' => 'b', 'type' => 'timer', 'config' => [], 'order' => 1, 'visible' => true],
                ['id' => 'c', 'type' => 'chart', 'config' => ['data_source' => 'qsos_per_band'], 'order' => 2, 'visible' => true],
            ],
        ]);

    $this->editor = fn () => Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])->call('enterEditMode');
});

/**
 * @return array<int, string>
 */
function widgetIds($component): array
{
    return $component->get('widgets')->pluck('id')->all();
}

test('undo reverts a reorder', function () {
    $component = ($this->editor)()->call('reorderWidgets', ['c', 'a', 'b']);

    expect(widgetIds($component))->toBe(['c', 'a', 'b']);

    $component->call('undo')->assertDispatched('widgets-reordered', widgetIds: ['a', 'b', 'c']);

    expect(widgetIds($component))->toBe(['a', 'b', 'c']);
});

test('undo reverts resize, visibility, configure, add and remove in reverse order', function () {
    $original = ($this->editor)()->get('widgets')->all();

    $component = ($this->editor)()
        ->call('resizeWidget', 'a', 2, 6)
        ->call('toggleVisibility', 'b')
        ->call('configureWidget', 'c')
        ->dispatch('widget-configured', type: 'chart', config: ['data_source' => 'qsos_per_mode'], mode: 'edit', colSpan: 1, rowSpan: 2)
        ->call('addWidget', 'timer')
        ->call('confirmRemoveWidget', 'a')
        ->call('removeWidget');

    expect($component->get('undoStack'))->toHaveCount(5);

    $component->call('undo');
    expect(widgetIds($component))->toContain('a');

    $component->call('undo');
    expect($component->get('widgets'))->toHaveCount(3);

    $component->call('undo');
    expect($component->get('widgets')->firstWhere('id', 'c')['config'])->toBe(['data_source' => 'qsos_per_band']);

    $component->call('undo');
    expect($component->get('widgets')->firstWhere('id', 'b')['visible'])->toBeTrue();

    $component->call('undo');
    expect($component->get('widgets')->all())->toBe($original)
        ->and($component->get('undoStack'))->toBe([]);
});

test('changes that do nothing are not recorded', function () {
    $component = ($this->editor)()
        ->call('reorderWidgets', ['a', 'b', 'c'])
        ->call('resizeWidget', 'missing', 2, 3);

    expect($component->get('undoStack'))->toBe([]);
});

test('undo does nothing outside edit mode or with no history', function () {
    $component = Livewire::test(DashboardEditor::class, ['dashboard' => $this->dashboard])->call('undo');
    expect(widgetIds($component))->toBe(['a', 'b', 'c']);

    $component->call('enterEditMode')->call('undo');
    expect(widgetIds($component))->toBe(['a', 'b', 'c']);
});

test('history is cleared when edit mode ends', function () {
    $component = ($this->editor)()
        ->call('resizeWidget', 'a', 2, 3)
        ->call('cancelEdit')
        ->call('enterEditMode');

    expect($component->get('undoStack'))->toBe([]);
});

test('history is capped', function () {
    $component = ($this->editor)();

    for ($i = 0; $i < DashboardEditor::MAX_UNDO_STEPS + 5; $i++) {
        $component->call('toggleVisibility', 'a');
    }

    expect($component->get('undoStack'))->toHaveCount(DashboardEditor::MAX_UNDO_STEPS);
});

test('the client cannot plant undo history', function () {
    ($this->editor)()->set('undoStack', [[['id' => 'x', 'type' => 'timer']]]);
})->throws(CannotUpdateLockedPropertyException::class);

test('the undo button reflects whether there is anything to undo', function () {
    $component = ($this->editor)()
        ->assertSeeHtml('aria-keyshortcuts="Control+Z Meta+Z"');

    expect($component->instance()->canUndo())->toBeFalse();

    $component->call('resizeWidget', 'a', 2, 3);

    expect($component->instance()->canUndo())->toBeTrue();
});
