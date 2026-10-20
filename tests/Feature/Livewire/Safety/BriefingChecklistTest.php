<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\ChecklistType;
use App\Livewire\Safety\ManageSafetyChecklist;
use App\Livewire\Safety\SiteSafetyChecklist;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\SafetyChecklistItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->event = Event::factory()->publicService()->create([
        'start_time' => appNow()->subHours(2),
        'end_time' => appNow()->addHours(6),
    ]);
    $this->eventConfig = EventConfiguration::factory()->publicService()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);
});

test('an unscored event seeds the briefing checklist instead of the ARRL items', function () {
    SafetyChecklistItem::seedDefaults($this->eventConfig);

    $items = SafetyChecklistItem::forEvent($this->eventConfig->id)->get();

    expect($items)->not->toBeEmpty()
        ->and($items->pluck('checklist_type')->unique()->all())->toBe([ChecklistType::Briefing])
        ->and($items->every(fn ($item) => $item->is_default === false))->toBeTrue();
});

test('briefing items can be deleted by a schedule manager', function () {
    Permission::firstOrCreate(['name' => 'manage-shifts']);
    $manager = User::factory()->create();
    $manager->givePermissionTo('manage-shifts');

    SafetyChecklistItem::seedDefaults($this->eventConfig);
    $item = SafetyChecklistItem::forEvent($this->eventConfig->id)->first();

    Livewire::actingAs($manager)->test(ManageSafetyChecklist::class)
        ->call('deleteItem', $item->id);

    expect(SafetyChecklistItem::find($item->id))->toBeNull();
});

test('new items on an unscored event can only be briefing items', function () {
    Permission::firstOrCreate(['name' => 'manage-shifts']);
    $manager = User::factory()->create();
    $manager->givePermissionTo('manage-shifts');

    Livewire::actingAs($manager)->test(ManageSafetyChecklist::class)
        ->assertSee('Volunteer Briefing')
        ->set('itemLabel', 'Bring a reflective vest')
        ->set('itemChecklistType', ChecklistType::SafetyOfficer->value)
        ->call('saveItem')
        ->assertHasErrors('itemChecklistType')
        ->set('itemChecklistType', ChecklistType::Briefing->value)
        ->call('saveItem')
        ->assertHasNoErrors();

    expect(SafetyChecklistItem::where('label', 'Bring a reflective vest')->first()->checklist_type)
        ->toBe(ChecklistType::Briefing);
});

test('any volunteer with a shift can check off briefing items without earning a bonus', function () {
    $role = ShiftRole::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Aid Station']);
    $shift = Shift::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'shift_role_id' => $role->id]);
    $volunteer = User::factory()->create();
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $volunteer->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
    ]);

    $component = Livewire::actingAs($volunteer)->test(SiteSafetyChecklist::class)
        ->assertSee('Volunteer Briefing')
        ->assertDontSee('bonus pts');

    foreach (SafetyChecklistItem::forEvent($this->eventConfig->id)->get() as $item) {
        $component->call('toggleItem', $item->id);
    }

    expect(SafetyChecklistItem::forEvent($this->eventConfig->id)->with('entry')->get()
        ->every(fn ($item) => $item->entry->is_completed))->toBeTrue()
        ->and(EventBonus::where('event_configuration_id', $this->eventConfig->id)->exists())->toBeFalse();
});

test('volunteers without a shift cannot check off briefing items', function () {
    Livewire::actingAs(User::factory()->create())->test(SiteSafetyChecklist::class)
        ->assertSee('You must be signed up for a shift at this event to check off items.');
});
