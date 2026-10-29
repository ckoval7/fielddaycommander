<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Behavior shared by My Equipment, Club Equipment and the Equipment Catalog,
 * checked on every page so it can't drift on one of them.
 */

use App\Livewire\Equipment\AllEquipmentList;
use App\Livewire\Equipment\ClubEquipmentList;
use App\Livewire\Equipment\EquipmentList;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();

    $permissions = ['manage-own-equipment', 'view-all-equipment', 'edit-any-equipment'];
    foreach ($permissions as $permission) {
        Permission::create(['name' => $permission]);
    }

    $role = Role::create(['name' => 'EquipmentManager', 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);
    $this->user->assignRole($role);

    $this->actingAs($this->user);
});

/**
 * Create equipment that shows on the given page for the signed-in user.
 *
 * @param  array<string, mixed>  $attributes
 */
function createListedEquipment(string $component, User $user, array $attributes = [], int $count = 1): Equipment|Collection
{
    $ownership = match ($component) {
        EquipmentList::class => ['owner_user_id' => $user->id],
        ClubEquipmentList::class => ['owner_user_id' => null, 'owner_organization_id' => Organization::factory()->create()->id],
        AllEquipmentList::class => ['owner_user_id' => User::factory()->create()->id],
    };

    $factory = Equipment::factory();

    return $count === 1
        ? $factory->create([...$ownership, ...$attributes])
        : $factory->count($count)->create([...$ownership, ...$attributes]);
}

dataset('equipment list pages', [
    'My Equipment' => [EquipmentList::class],
    'Club Equipment' => [ClubEquipmentList::class],
    'Equipment Catalog' => [AllEquipmentList::class],
]);

test('search and filters go back to the first page', function (string $component, string $property, string $value) {
    createListedEquipment($component, $this->user, ['type' => 'radio'], count: 30);

    Livewire::test($component)
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set($property, $value)
        ->assertSet('paginators.page', 1);
})->with('equipment list pages')->with([
    'search' => ['search', 'a'],
    'type' => ['typeFilter', 'radio'],
    'status' => ['statusFilter', 'available'],
]);

test('status filter separates available and committed equipment', function (string $component) {
    createListedEquipment($component, $this->user, ['model' => 'Idle Radio']);
    $committed = createListedEquipment($component, $this->user, ['model' => 'Busy Radio']);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);
    EquipmentEvent::factory()->create([
        'equipment_id' => $committed->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Livewire::test($component)
        ->set('statusFilter', 'available')
        ->assertSee('Idle Radio')
        ->assertDontSee('Busy Radio')
        ->set('statusFilter', 'committed')
        ->assertSee('Busy Radio')
        ->assertDontSee('Idle Radio');
})->with('equipment list pages');

test('sorts by the chosen column in either direction', function (string $component) {
    createListedEquipment($component, $this->user, ['make' => 'Alinco']);
    createListedEquipment($component, $this->user, ['make' => 'Yaesu']);

    Livewire::test($component)
        ->set('sortBy', 'make')
        ->set('sortDirection', 'asc')
        ->assertSeeInOrder(['Alinco', 'Yaesu'])
        ->set('sortDirection', 'desc')
        ->assertSeeInOrder(['Yaesu', 'Alinco']);
})->with('equipment list pages');

test('opens the photo viewer', function (string $component) {
    Livewire::test($component)
        ->call('viewPhoto', 'equipment-photos/rig.jpg', 'Icom IC-7300')
        ->assertSet('showPhotoModal', true)
        ->assertSet('photoPath', 'equipment-photos/rig.jpg')
        ->assertSet('photoDescription', 'Icom IC-7300');
})->with('equipment list pages');

test('deleting an item writes an audit log entry', function (string $component) {
    $item = createListedEquipment($component, $this->user, ['make' => 'Icom', 'model' => 'IC-705', 'type' => 'radio']);

    Livewire::test($component)
        ->call('deleteEquipment', $item->id)
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'success');

    expect(Equipment::find($item->id))->toBeNull();

    $log = AuditLog::where('action', 'equipment.deleted')->where('auditable_id', $item->id)->sole();
    expect($log->old_values)->toBe(['make' => 'Icom', 'model' => 'IC-705', 'type' => 'radio']);
})->with('equipment list pages');

test('refuses to delete a station primary radio and logs nothing', function (string $component) {
    $radio = createListedEquipment($component, $this->user, ['type' => 'radio', 'make' => 'Icom', 'model' => 'IC-7300']);
    Station::factory()->create(['radio_equipment_id' => $radio->id, 'name' => 'Phone 1']);

    Livewire::test($component)
        ->call('deleteEquipment', $radio->id)
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'error'
            && $params['title'] === 'Radio still in use')
        ->assertNotDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'success');

    expect(Equipment::find($radio->id))->not->toBeNull()
        ->and(AuditLog::where('action', 'equipment.deleted')->count())->toBe(0);
})->with('equipment list pages');

test('loads each item\'s current commitment up front instead of one query per item', function (string $component) {
    $event = Event::factory()->create([
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(4),
    ]);
    $commitAll = function (iterable $items) use ($event): void {
        foreach ($items as $item) {
            EquipmentEvent::factory()->create(['equipment_id' => $item->id, 'event_id' => $event->id, 'status' => 'committed']);
        }
    };
    $countRenderQueries = function () use ($component): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($component);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $commitAll(createListedEquipment($component, $this->user, count: 2));
    $countRenderQueries(); // warm the permission cache
    $withTwo = $countRenderQueries();

    $commitAll(createListedEquipment($component, $this->user, count: 6));
    $withEight = $countRenderQueries();

    $listed = Livewire::test($component)->get('equipment');

    expect($withEight)->toBe($withTwo)
        ->and($listed->first()->relationLoaded('activeCommitments'))->toBeTrue();
})->with('equipment list pages');

test('bulk delete writes an audit log entry for each item', function (string $component) {
    $items = createListedEquipment($component, $this->user, count: 2);

    Livewire::test($component)
        ->set('selectedIds', $items->pluck('id')->all())
        ->call('bulkDeleteEquipment');

    expect(Equipment::whereIn('id', $items->pluck('id'))->count())->toBe(0)
        ->and(AuditLog::where('action', 'equipment.deleted')->pluck('auditable_id')->sort()->values()->all())
        ->toBe($items->pluck('id')->sort()->values()->all());
})->with([
    'My Equipment' => [EquipmentList::class],
    'Club Equipment' => [ClubEquipmentList::class],
]);
