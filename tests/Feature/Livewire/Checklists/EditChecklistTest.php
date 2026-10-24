<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\ChecklistAnswerType;
use App\Enums\ChecklistCompletionRule;
use App\Enums\ChecklistScope;
use App\Livewire\Checklists\EditChecklist;
use App\Models\AuditLog;
use App\Models\Checklist;
use App\Models\ChecklistAnswer;
use App\Models\ChecklistItem;
use App\Models\ChecklistRun;
use App\Models\EventConfiguration;
use App\Models\ShiftRole;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->eventConfig = EventConfiguration::factory()->create();
    $this->checklist = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Go-Kit']);

    Permission::firstOrCreate(['name' => 'manage-shifts']);
    $this->manager = User::factory()->create();
    $this->manager->givePermissionTo('manage-shifts');
    $this->actingAs($this->manager);
});

test('requires manage-shifts', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])->assertForbidden();
});

test('saves the settings and who can complete it', function () {
    $role = ShiftRole::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Net Control']);

    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])
        ->set('name', 'Go-Kit Check')
        ->set('description', 'Before you leave home')
        ->set('scope', 'person')
        ->set('completionRule', 'all_items')
        ->set('shiftRoleIds', [$role->id])
        ->call('saveSettings')
        ->assertHasNoErrors();

    $this->checklist->refresh();

    expect($this->checklist->name)->toBe('Go-Kit Check')
        ->and($this->checklist->scope)->toBe(ChecklistScope::Person)
        ->and($this->checklist->completion_rule)->toBe(ChecklistCompletionRule::AllItems)
        ->and($this->checklist->shiftRoles->pluck('id')->all())->toBe([$role->id])
        ->and(AuditLog::where('action', 'checklist.updated')->exists())->toBeTrue();
});

test('a shift role from another event is refused', function () {
    $foreign = ShiftRole::factory()->create();

    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])
        ->set('shiftRoleIds', [$foreign->id])
        ->call('saveSettings')
        ->assertHasErrors('shiftRoleIds.0');
});

test('the scope is locked once someone has started the checklist', function () {
    ChecklistRun::factory()->create(['checklist_id' => $this->checklist->id]);

    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])
        ->assertSee('Can’t change after someone starts this checklist.')
        ->set('scope', 'station')
        ->call('saveSettings');

    expect($this->checklist->fresh()->scope)->toBe(ChecklistScope::Event);
});

test('a bonus checklist keeps its ARRL completion rule', function () {
    $safety = Checklist::factory()->safetyOfficer()->create(['event_configuration_id' => $this->eventConfig->id]);

    Livewire::test(EditChecklist::class, ['checklist' => $safety])
        ->set('completionRule', 'required_items')
        ->call('saveSettings');

    expect($safety->fresh()->completion_rule)->toBe(ChecklistCompletionRule::AllItems);
});

test('adds and edits items with an answer type', function () {
    $component = Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])
        ->call('openItemModal')
        ->set('itemLabel', 'Battery voltage')
        ->set('itemAnswerType', 'number')
        ->set('itemIsRequired', true)
        ->call('saveItem')
        ->assertHasNoErrors()
        ->assertSee('Battery voltage');

    $item = ChecklistItem::sole();
    expect($item->answer_type)->toBe(ChecklistAnswerType::Number)->and($item->is_required)->toBeTrue();

    $component->call('openItemModal', $item->id)
        ->assertSet('itemLabel', 'Battery voltage')
        ->set('itemHelpText', 'Resting voltage')
        ->call('saveItem');

    expect($item->fresh()->help_text)->toBe('Resting voltage')
        ->and(AuditLog::where('action', 'checklist.item.updated')->exists())->toBeTrue();
});

test('an item needs text', function () {
    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])
        ->call('openItemModal')
        ->call('saveItem')
        ->assertHasErrors('itemLabel');
});

test('deletes custom items but not ARRL items', function () {
    $custom = ChecklistItem::factory()->create(['checklist_id' => $this->checklist->id]);
    $arrl = ChecklistItem::factory()->default()->create(['checklist_id' => $this->checklist->id]);

    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])
        ->call('deleteItem', $custom->id)
        ->call('deleteItem', $arrl->id)
        ->assertDispatched('toast', fn ($name, $params) => $params['css'] === 'alert-error');

    expect($custom->fresh()->trashed())->toBeTrue()
        ->and($arrl->fresh()->trashed())->toBeFalse();
});

test('reorders items', function () {
    $first = ChecklistItem::factory()->create(['checklist_id' => $this->checklist->id, 'label' => 'First item', 'sort_order' => 0]);
    ChecklistItem::factory()->create(['checklist_id' => $this->checklist->id, 'label' => 'Second item', 'sort_order' => 1]);

    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])
        ->call('moveDown', $first->id)
        ->assertSeeInOrder(['Second item', 'First item']);
});

test('deleting the last unanswered item completes a run that had everything else done', function () {
    $done = ChecklistItem::factory()->create(['checklist_id' => $this->checklist->id]);
    $open = ChecklistItem::factory()->create(['checklist_id' => $this->checklist->id]);
    $run = ChecklistRun::factory()->create(['checklist_id' => $this->checklist->id]);
    ChecklistAnswer::factory()->create(['checklist_run_id' => $run->id, 'checklist_item_id' => $done->id]);

    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])
        ->call('deleteItem', $open->id);

    expect($run->fresh()->completed_at)->not->toBeNull();
});

test('an item from another checklist cannot be edited here', function () {
    $other = ChecklistItem::factory()->create();

    Livewire::test(EditChecklist::class, ['checklist' => $this->checklist])->call('deleteItem', $other->id)->assertNotFound();

    expect($other->fresh()->trashed())->toBeFalse();
});
