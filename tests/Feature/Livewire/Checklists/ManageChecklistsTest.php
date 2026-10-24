<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\ChecklistScope;
use App\Livewire\Checklists\ManageChecklists;
use App\Models\AuditLog;
use App\Models\BonusType;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\ShiftRole;
use App\Models\User;
use App\Support\ChecklistTemplates;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->event = Event::factory()->create([
        'name' => 'Field Day 2026',
        'rules_version' => '2025',
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    Permission::firstOrCreate(['name' => 'manage-shifts']);
    $this->manager = User::factory()->create();
    $this->manager->givePermissionTo('manage-shifts');
    $this->actingAs($this->manager);
});

test('requires manage-shifts', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageChecklists::class)->assertForbidden();
});

test('lists the event\'s checklists, seeding the defaults once', function () {
    Livewire::test(ManageChecklists::class)
        ->assertSee('Safety Officer')
        ->assertSee('Once for the event')
        ->assertSee('Earns a bonus');

    Livewire::test(ManageChecklists::class);

    expect(Checklist::count())->toBe(1);
});

test('adds a checklist from a template and opens it for editing', function () {
    Livewire::test(ManageChecklists::class)
        ->call('openAddModal')
        ->set('templateKey', 'station_setup')
        ->call('addChecklist')
        ->assertHasNoErrors()
        ->assertRedirect(route('checklists.edit', Checklist::where('template_key', 'station_setup')->sole()));

    $checklist = Checklist::where('template_key', 'station_setup')->sole();

    expect($checklist->scope)->toBe(ChecklistScope::Station)
        ->and($checklist->items()->count())->toBe(count(ChecklistTemplates::TEMPLATES['station_setup']['items']))
        ->and(AuditLog::where('action', 'checklist.created')->exists())->toBeTrue();
});

test('the ARRL templates are not offered to an unscored event', function () {
    $config = EventConfiguration::factory()->publicService()->create();

    expect(ChecklistTemplates::optionsFor($config))->not->toHaveKey('arrl_safety_officer')
        ->toHaveKey('briefing')
        ->and(ChecklistTemplates::optionsFor($this->eventConfig))->toHaveKey('arrl_safety_officer');
});

test('copies a checklist from a past event, without its answers', function () {
    $past = EventConfiguration::factory()->create();
    $source = Checklist::factory()->perStation()->create(['event_configuration_id' => $past->id, 'name' => 'Aid Station Opening']);
    ChecklistItem::factory()->count(3)->create(['checklist_id' => $source->id]);
    $source->shiftRoles()->attach(ShiftRole::factory()->create(['event_configuration_id' => $past->id, 'name' => 'Net Control']));
    $netControl = ShiftRole::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Net Control']);

    Livewire::test(ManageChecklists::class)
        ->set('addSource', 'copy')
        ->set('copyChecklistId', $source->id)
        ->call('addChecklist')
        ->assertHasNoErrors();

    $copy = Checklist::where('event_configuration_id', $this->eventConfig->id)->where('name', 'Aid Station Opening')->sole();

    expect($copy->scope)->toBe(ChecklistScope::Station)
        ->and($copy->items()->count())->toBe(3)
        ->and($copy->shiftRoles->pluck('id')->all())->toBe([$netControl->id])
        ->and($copy->runs()->count())->toBe(0);
});

test('a checklist on this event cannot be copied onto itself', function () {
    $own = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

    Livewire::test(ManageChecklists::class)
        ->set('addSource', 'copy')
        ->set('copyChecklistId', $own->id)
        ->call('addChecklist')
        ->assertHasErrors('copyChecklistId');
});

test('starts a blank checklist', function () {
    Livewire::test(ManageChecklists::class)
        ->set('addSource', 'blank')
        ->set('blankName', 'Shelter radio room')
        ->set('blankScope', 'person')
        ->call('addChecklist')
        ->assertHasNoErrors();

    expect(Checklist::where('name', 'Shelter radio room')->sole()->scope)->toBe(ChecklistScope::Person);
});

test('a blank checklist needs a name', function () {
    Livewire::test(ManageChecklists::class)
        ->set('addSource', 'blank')
        ->call('addChecklist')
        ->assertHasErrors('blankName');
});

test('deleting a bonus checklist removes the bonus it earned', function () {
    $bonusType = BonusType::factory()->create(['event_type_id' => $this->event->event_type_id, 'rules_version' => '2025', 'code' => 'safety_officer']);
    $checklist = Checklist::factory()->safetyOfficer()->create(['event_configuration_id' => $this->eventConfig->id]);
    EventBonus::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'bonus_type_id' => $bonusType->id]);

    Livewire::test(ManageChecklists::class)->call('deleteChecklist', $checklist->id);

    expect($checklist->fresh()->trashed())->toBeTrue()
        ->and(EventBonus::count())->toBe(0);
});

test('a deleted default checklist is not seeded again', function () {
    Livewire::test(ManageChecklists::class)->call('deleteChecklist', Checklist::sole()->id);
    Livewire::test(ManageChecklists::class);

    expect(Checklist::count())->toBe(0);
});

test('a checklist on another event cannot be deleted from here', function () {
    $other = Checklist::factory()->create();

    Livewire::test(ManageChecklists::class)->call('deleteChecklist', $other->id)->assertNotFound();

    expect($other->fresh()->trashed())->toBeFalse();
});

test('reorders checklists', function () {
    $first = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'First', 'sort_order' => 0]);
    $second = Checklist::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Second', 'sort_order' => 0]);

    Livewire::test(ManageChecklists::class)
        ->call('moveDown', $first->id)
        ->assertSeeInOrder(['Second', 'First']);
});
