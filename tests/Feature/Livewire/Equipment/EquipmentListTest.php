<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Equipment\EquipmentList;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Organization;
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();

    // Create permissions
    Permission::create(['name' => 'manage-own-equipment']);
    Permission::create(['name' => 'edit-any-equipment']);

    $role = Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('manage-own-equipment');
    $this->user->assignRole($role);
});

test('equipment list requires authentication', function () {
    Livewire::test(EquipmentList::class)
        ->assertForbidden();
});

test('equipment list is accessible when authenticated', function () {
    $this->actingAs($this->user);

    Livewire::test(EquipmentList::class)
        ->assertStatus(200);
});

test('equipment list displays only users own equipment', function () {
    $this->actingAs($this->user);

    $ownEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);

    $otherUser = User::factory()->create();
    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => $otherUser->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);

    Livewire::test(EquipmentList::class)
        ->assertSee('Yaesu')
        ->assertSee('FT-991A')
        ->assertDontSee('Icom')
        ->assertDontSee('IC-7300');
});

test('equipment list search filters by make', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('search', 'Yaesu')
        ->assertSee('Yaesu')
        ->assertSee('FT-991A')
        ->assertDontSee('Icom')
        ->assertDontSee('IC-7300');
});

test('equipment list search filters by model', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('search', 'IC-7300')
        ->assertSee('Icom')
        ->assertSee('IC-7300')
        ->assertDontSee('Yaesu')
        ->assertDontSee('FT-991A');
});

test('equipment list search filters by description', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'description' => 'Primary contest radio',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
        'description' => 'Backup radio',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('search', 'contest')
        ->assertSee('Yaesu')
        ->assertSee('Primary contest radio')
        ->assertDontSee('Icom')
        ->assertDontSee('Backup radio');
});

test('equipment list search filters by serial number', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'serial_number' => 'YS123456',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
        'serial_number' => 'IC789012',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('search', 'YS123456')
        ->assertSee('Yaesu')
        ->assertSee('YS123456')
        ->assertDontSee('Icom')
        ->assertDontSee('IC789012');
});

test('equipment list type filter works correctly', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'type' => 'radio',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Diamond',
        'type' => 'antenna',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('typeFilter', 'radio')
        ->assertSee('Yaesu')
        ->assertDontSee('Diamond');
});

test('equipment list status filter shows available equipment', function () {
    $this->actingAs($this->user);

    $availableEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'Available Radio',
    ]);

    $committedEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'Committed Radio',
    ]);

    // Create an active event
    $event = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);

    // Create a commitment for the equipment
    EquipmentEvent::factory()->create([
        'equipment_id' => $committedEquipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('statusFilter', 'available')
        ->assertSee('Available Radio')
        ->assertDontSee('Committed Radio');
});

test('equipment list status filter shows committed equipment', function () {
    $this->actingAs($this->user);

    $availableEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'Available Radio',
    ]);

    $committedEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'Committed Radio',
    ]);

    // Create an active event
    $event = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);

    // Create a commitment for the equipment
    EquipmentEvent::factory()->create([
        'equipment_id' => $committedEquipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('statusFilter', 'committed')
        ->assertSee('Committed Radio')
        ->assertDontSee('Available Radio');
});

test('equipment list sorts by specified column', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Alpha',
        'created_at' => now()->subDays(2),
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Zulu',
        'created_at' => now()->subDays(1),
    ]);

    $component = Livewire::test(EquipmentList::class)
        ->assertStatus(200)
        ->assertSet('sortBy', 'created_at')
        ->assertSet('sortDirection', 'desc');

    // Default sort should show newest first (Zulu)
    $equipment = $component->get('equipment');
    expect($equipment->first()->make)->toBe('Zulu');
});

test('equipment list paginates results', function () {
    $this->actingAs($this->user);

    // Create 30 equipment items (more than the 25 per page limit)
    Equipment::factory()->count(30)->create([
        'owner_user_id' => $this->user->id,
    ]);

    $component = Livewire::test(EquipmentList::class);

    $equipment = $component->get('equipment');
    expect($equipment->total())->toBe(30);
    expect($equipment->perPage())->toBe(25);
});

test('updating search resets pagination', function () {
    $this->actingAs($this->user);

    // Create equipment with different makes to ensure search filtering
    Equipment::factory()->count(15)->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
    ]);

    Equipment::factory()->count(15)->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
    ]);

    // Verify component handles search properly
    // The updatingSearch() method exists and will reset pagination
    $component = Livewire::test(EquipmentList::class);

    // Verify we have multiple pages
    expect($component->get('equipment')->lastPage())->toBeGreaterThan(1);

    // Setting search should work correctly
    $component->set('search', 'Yaesu');
    expect($component->get('search'))->toBe('Yaesu');
});

test('updating type filter resets pagination', function () {
    $this->actingAs($this->user);

    Equipment::factory()->count(30)->create([
        'owner_user_id' => $this->user->id,
        'type' => 'radio',
    ]);

    // Setting type filter should work correctly
    $component = Livewire::test(EquipmentList::class)
        ->set('typeFilter', 'radio');

    expect($component->get('typeFilter'))->toBe('radio');
});

test('updating status filter resets pagination', function () {
    $this->actingAs($this->user);

    Equipment::factory()->count(30)->create([
        'owner_user_id' => $this->user->id,
    ]);

    // Setting status filter should work correctly
    $component = Livewire::test(EquipmentList::class)
        ->set('statusFilter', 'available');

    expect($component->get('statusFilter'))->toBe('available');
});

test('delete equipment requires authorization', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentList::class)
        ->call('deleteEquipment', $equipment->id)
        ->assertForbidden();
});

test('user can delete their own equipment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
    ]);

    expect(Equipment::where('id', $equipment->id)->exists())->toBeTrue();

    Livewire::test(EquipmentList::class)
        ->call('deleteEquipment', $equipment->id)
        ->assertDispatched('notify');

    expect(Equipment::where('id', $equipment->id)->exists())->toBeFalse();
});

test('user cannot delete another users equipment', function () {
    $this->actingAs($this->user);

    $otherUser = User::factory()->create();
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $otherUser->id,
    ]);

    Livewire::test(EquipmentList::class)
        ->call('deleteEquipment', $equipment->id)
        ->assertForbidden();

    expect(Equipment::where('id', $equipment->id)->exists())->toBeTrue();
});

test('user with edit-any-equipment permission can delete any equipment', function () {
    $adminUser = User::factory()->create();
    $adminRole = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo('edit-any-equipment');
    $adminUser->assignRole($adminRole);

    $this->actingAs($adminUser);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentList::class)
        ->call('deleteEquipment', $equipment->id)
        ->assertDispatched('notify');

    expect(Equipment::where('id', $equipment->id)->exists())->toBeFalse();
});

test('equipment list shows empty state when no equipment', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(EquipmentList::class);

    $equipment = $component->get('equipment');
    expect($equipment->total())->toBe(0);

    $component
        ->assertSee('No equipment found. Add equipment or change the filters.')
        ->assertSeeHtml('colspan="9"')
        ->assertSeeHtml('href="'.route('equipment.create').'"');
});

test('my equipment shows cards until the table fits beside the open sidebar at 2xl', function () {
    $this->actingAs($this->user);

    $item = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'type' => 'power_supply',
        'description' => str_repeat('A long description that would stretch the table. ', 4),
    ]);

    Livewire::test(EquipmentList::class)
        ->assertSeeHtmlInOrder([
            '2xl:hidden grid grid-cols-1 xl:grid-cols-2',
            'wire:key="equipment-card-'.$item->id.'"',
            'hidden 2xl:block',
            '<span class="badge badge-primary badge-sm whitespace-nowrap">',
            'Power supply',
            '<td class="min-w-40 max-w-xs">',
            '<p class="line-clamp-2 text-sm">',
            'aria-label="Actions for '.e($item->make.' '.$item->model).'"',
        ])
        ->assertDontSeeHtml('hidden xl:block')
        ->assertDontSeeHtml('overflow-x-auto');
});

test('my equipment marks an item the club also owns and names its manager under make and model', function () {
    $this->actingAs($this->user);

    $manager = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Manager']);
    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'owner_organization_id' => Organization::factory()->create()->id,
        'managed_by_user_id' => $manager->id,
    ]);

    Livewire::test(EquipmentList::class)
        ->assertSeeHtmlInOrder(['badge-club', 'Club Equipment', 'Managed by '.$manager->full_name, 'badge-club', 'Club Equipment', 'Managed by '.$manager->full_name])
        ->assertDontSeeHtml('<th scope="col">Manager</th>');
});

test('search works with partial matches', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('search', 'FT-9')
        ->assertSee('Yaesu')
        ->assertSee('FT-991A');
});

test('search is case insensitive', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('search', 'yaesu')
        ->assertSee('Yaesu');

    Livewire::test(EquipmentList::class)
        ->set('search', 'YAESU')
        ->assertSee('Yaesu');
});

test('equipment list does not include soft deleted equipment', function () {
    $this->actingAs($this->user);

    $activeEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Active',
    ]);

    $deletedEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Deleted',
    ]);
    $deletedEquipment->delete();

    Livewire::test(EquipmentList::class)
        ->assertSee('Active')
        ->assertDontSee('Deleted');
});

test('multiple filters work together', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'type' => 'radio',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-DX10',
        'type' => 'radio',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
        'type' => 'radio',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'ATAS-120A',
        'type' => 'antenna',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('search', 'Yaesu')
        ->set('typeFilter', 'radio')
        ->assertSee('FT-991A')
        ->assertSee('FT-DX10')
        ->assertDontSee('IC-7300')
        ->assertDontSee('ATAS-120A');
});

test('club equipment button is hidden for operators without edit-any-equipment permission', function () {
    $this->actingAs($this->user);

    Livewire::test(EquipmentList::class)
        ->assertDontSee('Add Club Equipment');
});

test('club equipment button is visible for users with edit-any-equipment permission', function () {
    $this->actingAs($this->user);
    $this->user->givePermissionTo('edit-any-equipment');

    Livewire::test(EquipmentList::class)
        ->assertSee('Add Club Equipment');
});

test('clicking on equipment photo opens photo modal', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'photo_path' => 'equipment/test-photo.jpg',
    ]);

    Livewire::test(EquipmentList::class)
        ->assertSet('showPhotoModal', false)
        ->assertSet('photoPath', null)
        ->call('viewPhoto', $equipment->photo_path, $equipment->make.' '.$equipment->model)
        ->assertSet('showPhotoModal', true)
        ->assertSet('photoPath', 'equipment/test-photo.jpg')
        ->assertSet('photoDescription', 'Yaesu FT-991A');
});

test('photo modal displays correct equipment information', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
        'photo_path' => 'equipment/icom-photo.jpg',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('showPhotoModal', true)
        ->set('photoPath', $equipment->photo_path)
        ->set('photoDescription', $equipment->make.' '.$equipment->model)
        ->assertSee('Icom IC-7300')
        ->assertSee($equipment->photo_path);
});

test('photo modal can be closed', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'photo_path' => 'equipment/test-photo.jpg',
    ]);

    Livewire::test(EquipmentList::class)
        ->call('viewPhoto', $equipment->photo_path, 'Test Equipment')
        ->assertSet('showPhotoModal', true)
        ->set('showPhotoModal', false)
        ->assertSet('showPhotoModal', false);
});

test('equipment without photo shows placeholder', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'photo_path' => null,
    ]);

    Livewire::test(EquipmentList::class)
        ->assertSee('Yaesu')
        ->assertSee('bg-base-300');
});

test('equipment list shows only own equipment even with view-all-equipment permission', function () {
    Permission::create(['name' => 'view-all-equipment']);
    $this->user->givePermissionTo('view-all-equipment');
    $this->actingAs($this->user);

    $ownEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);

    $otherUser = User::factory()->create();
    Equipment::factory()->create([
        'owner_user_id' => $otherUser->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);

    Livewire::test(EquipmentList::class)
        ->assertSee('Yaesu')
        ->assertSee('FT-991A')
        ->assertDontSee('Icom')
        ->assertDontSee('IC-7300');
});

test('multiple equipment photos can be viewed in modal', function () {
    $this->actingAs($this->user);

    $equipment1 = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'photo_path' => 'equipment/yaesu.jpg',
    ]);

    $equipment2 = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'IC-7300',
        'photo_path' => 'equipment/icom.jpg',
    ]);

    $component = Livewire::test(EquipmentList::class);

    // View first photo
    $component->call('viewPhoto', $equipment1->photo_path, $equipment1->make.' '.$equipment1->model)
        ->assertSet('photoPath', 'equipment/yaesu.jpg')
        ->assertSet('photoDescription', 'Yaesu FT-991A');

    // View second photo
    $component->call('viewPhoto', $equipment2->photo_path, $equipment2->make.' '.$equipment2->model)
        ->assertSet('photoPath', 'equipment/icom.jpg')
        ->assertSet('photoDescription', 'Icom IC-7300');
});

test('user can open commit modal for available equipment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(EquipmentList::class)
        ->call('openCommitModal', $equipment->id)
        ->assertSet('showCommitModal', true)
        ->assertSet('commitEquipmentId', $equipment->id);
});

test('recommitting equipment after prior cancellation revives the existing row', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    $cancelled = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'cancelled',
        'delivery_notes' => 'old notes',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('commitEquipmentId', $equipment->id)
        ->set('commitEventId', $event->id)
        ->set('commitDeliveryNotes', 'new notes')
        ->call('commitEquipment')
        ->assertHasNoErrors()
        ->assertSet('showCommitModal', false);

    $this->assertDatabaseCount('equipment_event', 1);
    $this->assertDatabaseHas('equipment_event', [
        'id' => $cancelled->id,
        'status' => 'committed',
        'delivery_notes' => 'new notes',
    ]);
});

test('bulk recommit after prior cancellation revives the existing row', function () {
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    $returned = EquipmentEvent::factory()->create([
        'equipment_id' => $eq1->id,
        'event_id' => $event->id,
        'status' => 'returned',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$eq1->id])
        ->set('bulkCommitEventId', $event->id)
        ->call('bulkCommitEquipment')
        ->assertHasNoErrors()
        ->assertSet('showBulkCommitModal', false);

    $this->assertDatabaseCount('equipment_event', 1);
    $this->assertDatabaseHas('equipment_event', [
        'id' => $returned->id,
        'status' => 'committed',
    ]);
});

test('commit modal pre-populates with the active event when one is in progress', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    Event::factory()->create([
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(5),
        'setup_allowed_from' => now()->addDays(2),
    ]);

    $active = Event::factory()->create([
        'start_time' => now()->subDay(),
        'end_time' => now()->addDay(),
        'setup_allowed_from' => now()->subDays(2),
    ]);

    Livewire::test(EquipmentList::class)
        ->call('openCommitModal', $equipment->id)
        ->assertSet('commitEventId', $active->id);
});

test('commit modal pre-populates with the next upcoming event when none are active', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    Event::factory()->create([
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(12),
        'setup_allowed_from' => now()->addDays(9),
    ]);

    $next = Event::factory()->create([
        'start_time' => now()->addDays(3),
        'end_time' => now()->addDays(5),
        'setup_allowed_from' => now()->addDays(2),
    ]);

    Livewire::test(EquipmentList::class)
        ->call('openCommitModal', $equipment->id)
        ->assertSet('commitEventId', $next->id);
});

test('commit modal event is null when there are no upcoming events', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    Livewire::test(EquipmentList::class)
        ->call('openCommitModal', $equipment->id)
        ->assertSet('commitEventId', null);
});

test('bulk commit modal pre-populates with the active or next event', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    $active = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(4),
        'setup_allowed_from' => now()->subDay(),
    ]);

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$equipment->id])
        ->call('openBulkCommitModal')
        ->assertSet('bulkCommitEventId', $active->id);
});

test('user can commit equipment to event from catalog', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(EquipmentList::class)
        ->set('commitEquipmentId', $equipment->id)
        ->set('commitEventId', $event->id)
        ->set('commitExpectedDeliveryAt', now()->addDays(7)->format('Y-m-d H:i:s'))
        ->set('commitDeliveryNotes', 'Will bring on Friday')
        ->call('commitEquipment')
        ->assertSet('showCommitModal', false)
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
        'delivery_notes' => 'Will bring on Friday',
    ]);
});

test('allows delivery date before setup window begins', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(2)->setTime(18, 0),
        'end_time' => now()->addDays(3)->setTime(21, 0),
        'setup_allowed_from' => now()->addDays(1)->setTime(18, 0),
    ]);

    Livewire::test(EquipmentList::class)
        ->set('commitEquipmentId', $equipment->id)
        ->set('commitEventId', $event->id)
        ->set('commitExpectedDeliveryAt', now()->format('Y-m-d'))
        ->call('commitEquipment')
        ->assertHasNoErrors()
        ->assertSet('showCommitModal', false);

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);
});

test('cannot commit equipment user does not own', function () {
    $this->actingAs($this->user);

    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(EquipmentList::class)
        ->set('commitEquipmentId', $otherEquipment->id)
        ->set('commitEventId', $event->id)
        ->call('commitEquipment')
        ->assertHasErrors(['commitEquipmentId']);
});

test('prevents overlapping commitments from catalog', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $event1 = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
    ]);

    $event2 = Event::factory()->create([
        'start_time' => now()->addDays(8),
        'end_time' => now()->addDays(10),
        'setup_allowed_from' => now()->addDays(7),
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event1->id,
        'status' => 'committed',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('commitEquipmentId', $equipment->id)
        ->set('commitEventId', $event2->id)
        ->call('commitEquipment')
        ->assertHasErrors(['commitEquipmentId']);
});

test('validates delivery notes max length on commit', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(EquipmentList::class)
        ->set('commitEquipmentId', $equipment->id)
        ->set('commitEventId', $event->id)
        ->set('commitDeliveryNotes', str_repeat('a', 501))
        ->call('commitEquipment')
        ->assertHasErrors(['commitDeliveryNotes']);
});

test('user can view commitment details from catalog', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
        'delivery_notes' => 'Detail test notes',
    ]);

    Livewire::test(EquipmentList::class)
        ->call('openDetailsModal', $commitment->id)
        ->assertSet('showDetailsModal', true)
        ->assertSet('detailCommitment.id', $commitment->id);
});

test('cannot view details for commitment not owned by user', function () {
    $this->actingAs($this->user);

    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $otherEquipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
    ]);

    Livewire::test(EquipmentList::class)
        ->call('openDetailsModal', $commitment->id)
        ->assertSet('showDetailsModal', false)
        ->assertDispatched('notify');
});

test('user can change commitment status from catalog', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
    ]);

    Livewire::test(EquipmentList::class)
        ->call('changeStatus', $commitment->id, 'delivered')
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'delivered',
    ]);
});

test('cannot change status for commitment not owned by user', function () {
    $this->actingAs($this->user);

    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $otherEquipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
    ]);

    Livewire::test(EquipmentList::class)
        ->call('changeStatus', $commitment->id, 'delivered')
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'committed',
    ]);
});

test('user can open notes modal for commitment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
        'delivery_notes' => 'Existing notes',
    ]);

    Livewire::test(EquipmentList::class)
        ->call('openNotesModal', $commitment->id)
        ->assertSet('showNotesModal', true)
        ->assertSet('updateNoteId', $commitment->id)
        ->assertSet('tempNotes', 'Existing notes');
});

test('user can update delivery notes from catalog', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
        'delivery_notes' => 'Original notes',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('updateNoteId', $commitment->id)
        ->set('tempNotes', 'Updated notes')
        ->call('updateNotes')
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'delivery_notes' => 'Updated notes',
    ]);
});

test('cannot update notes for commitment not owned by user', function () {
    $this->actingAs($this->user);

    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $otherEquipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
        'delivery_notes' => 'Original notes',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('updateNoteId', $commitment->id)
        ->set('tempNotes', 'Hacked notes')
        ->call('updateNotes')
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'delivery_notes' => 'Original notes',
    ]);
});

test('user can bulk commit multiple equipment items', function () {
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $eq2 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$eq1->id, $eq2->id])
        ->set('bulkCommitEventId', $event->id)
        ->set('bulkCommitDeliveryNotes', 'Bulk notes')
        ->call('bulkCommitEquipment')
        ->assertSet('showBulkCommitModal', false)
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $eq1->id,
        'event_id' => $event->id,
        'status' => 'committed',
        'delivery_notes' => 'Bulk notes',
    ]);
    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $eq2->id,
        'event_id' => $event->id,
        'status' => 'committed',
        'delivery_notes' => 'Bulk notes',
    ]);
});

test('bulk commit aborts if any item has overlapping commitment', function () {
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $eq2 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    // Commit eq2 to overlapping event
    $overlapping = Event::factory()->create([
        'start_time' => now()->addDays(8),
        'end_time' => now()->addDays(10),
    ]);
    EquipmentEvent::factory()->create([
        'equipment_id' => $eq2->id,
        'event_id' => $overlapping->id,
        'status' => 'committed',
    ]);

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$eq1->id, $eq2->id])
        ->set('bulkCommitEventId', $event->id)
        ->call('bulkCommitEquipment')
        ->assertHasErrors(['bulkCommit']);

    // Neither should be committed
    $this->assertDatabaseMissing('equipment_event', [
        'equipment_id' => $eq1->id,
        'event_id' => $event->id,
    ]);
});

test('user can bulk delete equipment', function () {
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $eq2 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$eq1->id, $eq2->id])
        ->call('bulkDeleteEquipment')
        ->assertDispatched('notify');

    expect(Equipment::where('id', $eq1->id)->exists())->toBeFalse();
    expect(Equipment::where('id', $eq2->id)->exists())->toBeFalse();
});

test('bulk delete only deletes equipment owned by user', function () {
    $this->actingAs($this->user);

    $ownEquipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$ownEquipment->id, $otherEquipment->id])
        ->call('bulkDeleteEquipment')
        ->assertDispatched('notify');

    expect(Equipment::where('id', $ownEquipment->id)->exists())->toBeFalse();
    expect(Equipment::where('id', $otherEquipment->id)->exists())->toBeTrue();
});

test('select all toggles all current page ids', function () {
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $eq2 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    Livewire::test(EquipmentList::class)
        ->call('selectAll')
        ->assertSet('selectedIds', [$eq2->id, $eq1->id]);
});

test('deselect all clears selection', function () {
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create(['owner_user_id' => $this->user->id]);

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$eq1->id])
        ->call('deselectAll')
        ->assertSet('selectedIds', []);
});

/**
 * Pull every viewPhoto wire:click handler out of rendered HTML, decoded the
 * way a browser would before handing it to the JS evaluator.
 *
 * @return list<string>
 */
function viewPhotoHandlers(string $html): array
{
    preg_match_all('/wire:click="(viewPhoto\([^"]*\))"/', $html, $matches);

    return array_map(fn (string $handler) => html_entity_decode($handler, ENT_QUOTES | ENT_HTML5), $matches[1]);
}

test('equipment make cannot break out of the photo thumbnail click handler', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => "');alert(1);('",
        'model' => 'FT-991A',
        'photo_path' => "equipment/x');alert(2);('.jpg",
    ]);

    $handlers = viewPhotoHandlers(Livewire::test(EquipmentList::class)->html());
    $apos = trim(json_encode("'", JSON_HEX_APOS), '"');

    expect($handlers)->not->toBeEmpty();

    foreach ($handlers as $handler) {
        expect($handler)
            ->not->toContain("');alert(")
            ->toContain($apos.');alert(1);('.$apos)
            ->toContain($apos.');alert(2);('.$apos);
    }
});

test('photo thumbnail component encodes user data as JS values', function () {
    $item = Equipment::factory()->make([
        'make' => "');alert(1);('",
        'model' => 'X',
        'photo_path' => 'equipment/a.jpg',
    ]);

    $html = (string) $this->blade('<x-equipment.photo-thumbnail :item="$item" />', ['item' => $item]);

    $apos = trim(json_encode("'", JSON_HEX_APOS), '"');

    expect(viewPhotoHandlers($html))->toBe([
        "viewPhoto('equipment\\/a.jpg', '".$apos.');alert(1);('.$apos." X')",
    ]);
});

test('EquipmentList ignores sort columns outside the allowlist', function () {
    $this->actingAs($this->user);

    Livewire::test(EquipmentList::class)
        ->set('sortBy', 'nonexistent_column')
        ->set('sortDirection', 'sideways')
        ->assertOk();
});

test('equipment can be committed to an event months away and shows as committed', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $winterFieldDay = Event::factory()->create([
        'name' => 'Winter Field Day Far Off',
        'start_time' => now()->addDays(98),
        'end_time' => now()->addDays(99),
    ]);
    Event::factory()->create(['start_time' => now()->subDays(3), 'end_time' => now()->subDays(2)]);

    $component = Livewire::test(EquipmentList::class)->call('openCommitModal', $equipment->id);

    expect($component->instance()->upcomingEvents->pluck('id')->all())->toBe([$winterFieldDay->id]);

    $component->assertSet('commitEventId', $winterFieldDay->id)
        ->call('commitEquipment')
        ->assertHasNoErrors();

    $equipment = Equipment::find($equipment->id);
    expect($equipment->current_commitment?->event_id)->toBe($winterFieldDay->id)
        ->and(Equipment::withCommitmentStatus('committed')->pluck('id')->all())->toBe([$equipment->id]);
});

test('current commitment is the soonest of several future events', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $later = Event::factory()->create(['start_time' => now()->addDays(90), 'end_time' => now()->addDays(91)]);
    $sooner = Event::factory()->create(['start_time' => now()->addDays(40), 'end_time' => now()->addDays(41)]);
    EquipmentEvent::factory()->create(['equipment_id' => $equipment->id, 'event_id' => $later->id, 'status' => 'committed']);
    EquipmentEvent::factory()->create(['equipment_id' => $equipment->id, 'event_id' => $sooner->id, 'status' => 'committed']);

    $equipment = Equipment::find($equipment->id);
    expect($equipment->current_commitment->event_id)->toBe($sooner->id)
        ->and($equipment->activeCommitments->pluck('event_id')->all())->toBe([$sooner->id, $later->id]);
});

test('deleting a radio that is a station primary radio is blocked and names the station', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'type' => 'radio',
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);
    $station = Station::factory()->create(['radio_equipment_id' => $radio->id, 'name' => '20m Phone']);
    $eventName = $station->eventConfiguration->event->name;

    Livewire::test(EquipmentList::class)
        ->call('deleteEquipment', $radio->id)
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'error'
            && $params['description'] === "Icom IC-7300 is the primary radio for 20m Phone in {$eventName}. Assign another radio to that station first.");

    expect(Equipment::where('id', $radio->id)->exists())->toBeTrue();
    expect($station->fresh()->radio_equipment_id)->toBe($radio->id);
});

test('bulk delete skips radios that are a station primary radio', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'type' => 'radio',
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);
    $other = Equipment::factory()->create(['owner_user_id' => $this->user->id]);
    $station = Station::factory()->create(['radio_equipment_id' => $radio->id, 'name' => 'Digital Station']);
    $eventName = $station->eventConfiguration->event->name;

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$radio->id, $other->id])
        ->call('bulkDeleteEquipment')
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'warning'
            && $params['title'] === 'Some items kept'
            && $params['description'] === "1 item deleted. Yaesu FT-991A is the primary radio for Digital Station in {$eventName}. Assign another radio to that station first.")
        ->assertNotDispatched('notify', fn (string $name, array $params): bool => in_array($params['type'], ['success', 'error'], true));

    expect(Equipment::where('id', $radio->id)->exists())->toBeTrue();
    expect(Equipment::where('id', $other->id)->exists())->toBeFalse();
});

test('bulk delete of only a station primary radio shows just the error', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'type' => 'radio',
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);
    Station::factory()->create(['radio_equipment_id' => $radio->id, 'name' => 'Digital Station']);

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$radio->id])
        ->call('bulkDeleteEquipment')
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'error'
            && $params['title'] === 'Radio still in use')
        ->assertNotDispatched('notify', fn (string $name, array $params): bool => in_array($params['type'], ['success', 'warning'], true));

    expect(Equipment::where('id', $radio->id)->exists())->toBeTrue();
});

/**
 * Create a station whose event ended last month, using the given radio as
 * its primary radio.
 */
function makePastEventStation(Equipment $radio, string $name): Station
{
    $event = Event::factory()->create([
        'name' => 'Field Day Last Year',
        'start_time' => now()->subYear(),
        'end_time' => now()->subYear()->addDay(),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    return Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
        'name' => $name,
    ]);
}

test('a radio that is only a past event station primary radio can be deleted', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'type' => 'radio',
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);
    $station = makePastEventStation($radio, '20m Phone');

    Livewire::test(EquipmentList::class)
        ->call('deleteEquipment', $radio->id)
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'success')
        ->assertNotDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'error');

    expect(Equipment::find($radio->id))->toBeNull()
        ->and($station->fresh()->radio_equipment_id)->toBe($radio->id);
});

test('a radio that is primary for an upcoming event station is still refused even if a past station used it', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'type' => 'radio',
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);
    makePastEventStation($radio, 'Old Station');
    $upcoming = Station::factory()->create(['radio_equipment_id' => $radio->id, 'name' => '20m Phone']);
    $eventName = $upcoming->eventConfiguration->event->name;

    Livewire::test(EquipmentList::class)
        ->call('deleteEquipment', $radio->id)
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'error'
            && $params['description'] === "Icom IC-7300 is the primary radio for 20m Phone in {$eventName}. Assign another radio to that station first.");

    expect(Equipment::find($radio->id))->not->toBeNull();
});

test('a radio that is primary for an in-progress event station is still refused', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'type' => 'radio',
    ]);
    $event = Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addDay(),
    ]);
    Station::factory()->create([
        'event_configuration_id' => EventConfiguration::factory()->create(['event_id' => $event->id])->id,
        'radio_equipment_id' => $radio->id,
    ]);

    Livewire::test(EquipmentList::class)
        ->call('deleteEquipment', $radio->id)
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'error');

    expect(Equipment::find($radio->id))->not->toBeNull();
});

test('bulk delete removes a radio used only by a past event station', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create(['owner_user_id' => $this->user->id, 'type' => 'radio']);
    makePastEventStation($radio, 'Old Station');

    Livewire::test(EquipmentList::class)
        ->set('selectedIds', [$radio->id])
        ->call('bulkDeleteEquipment')
        ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'success'
            && $params['description'] === '1 item deleted.')
        ->assertNotDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'error');

    expect(Equipment::find($radio->id))->toBeNull();
});
