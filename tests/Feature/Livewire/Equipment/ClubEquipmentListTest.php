<?php

use App\Livewire\Equipment\ClubEquipmentList;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->organization = Organization::factory()->create(['name' => 'Test Club']);

    Permission::create(['name' => 'edit-any-equipment']);
    Permission::create(['name' => 'manage-own-equipment']);
});

function giveEquipmentManagerRole($user): void
{
    $role = Role::firstOrCreate(['name' => 'EquipmentManager', 'guard_name' => 'web']);
    $role->givePermissionTo('edit-any-equipment');
    $user->assignRole($role);
}

test('club equipment list requires authentication', function () {
    $this->get(route('equipment.club'))
        ->assertRedirect(route('login'));
});

test('club equipment list is accessible to any authenticated user', function () {
    $this->actingAs($this->user);

    Livewire::test(ClubEquipmentList::class)
        ->assertStatus(200);
});

test('club equipment list shows only club-owned equipment', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Kenwood',
        'model' => 'TS-990S',
    ]);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'owner_organization_id' => null,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->assertSee('Kenwood')
        ->assertSee('TS-990S')
        ->assertDontSee('Yaesu')
        ->assertDontSee('FT-991A');
});

test('club equipment list search works', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Kenwood',
        'model' => 'TS-990S',
    ]);

    Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Icom',
        'model' => 'IC-7610',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->set('search', 'Kenwood')
        ->assertSee('Kenwood')
        ->assertDontSee('Icom');
});

test('club equipment list type filter works', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Kenwood',
        'type' => 'radio',
    ]);

    Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Diamond',
        'type' => 'antenna',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->set('typeFilter', 'radio')
        ->assertSee('Kenwood')
        ->assertDontSee('Diamond');
});

test('club equipment list paginates results', function () {
    $this->actingAs($this->user);

    Equipment::factory()->count(30)->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $component = Livewire::test(ClubEquipmentList::class);
    $equipment = $component->get('equipment');
    expect($equipment->total())->toBe(30);
    expect($equipment->perPage())->toBe(25);
});

test('regular user does not see edit or delete actions', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Kenwood',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->assertDontSee('Edit')
        ->assertDontSee('Delete');
});

test('user with edit-any-equipment sees actions column', function () {
    $role = Role::create(['name' => 'EquipmentManager', 'guard_name' => 'web']);
    $role->givePermissionTo('edit-any-equipment');
    $this->user->assignRole($role);

    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Kenwood',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->assertSee('Actions');
});

test('user with edit-any-equipment can delete club equipment', function () {
    $role = Role::create(['name' => 'EquipmentManager', 'guard_name' => 'web']);
    $role->givePermissionTo('edit-any-equipment');
    $this->user->assignRole($role);

    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Kenwood',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('deleteEquipment', $equipment->id)
        ->assertDispatched('notify');

    expect(Equipment::find($equipment->id))->toBeNull();
});

test('regular user cannot delete club equipment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
        'make' => 'Kenwood',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('deleteEquipment', $equipment->id)
        ->assertForbidden();
});

// --- Commit flow tests ---

test('equipment manager can open commit modal for club equipment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('openCommitModal', $equipment->id)
        ->assertSet('showCommitModal', true)
        ->assertSet('commitEquipmentId', $equipment->id);
});

test('regular user cannot open commit modal for club equipment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('openCommitModal', $equipment->id)
        ->assertForbidden();
});

test('equipment manager can commit club equipment to event', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(ClubEquipmentList::class)
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

test('regular user cannot commit club equipment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->set('commitEquipmentId', $equipment->id)
        ->set('commitEventId', $event->id)
        ->call('commitEquipment')
        ->assertForbidden();
});

test('commit validates equipment is club-owned', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $personalEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'owner_organization_id' => null,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->set('commitEquipmentId', $personalEquipment->id)
        ->set('commitEventId', $event->id)
        ->call('commitEquipment')
        ->assertHasErrors(['commitEquipmentId']);
});

test('prevents overlapping commitments for club equipment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
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

    Livewire::test(ClubEquipmentList::class)
        ->set('commitEquipmentId', $equipment->id)
        ->set('commitEventId', $event2->id)
        ->call('commitEquipment')
        ->assertHasErrors(['commitEquipmentId']);
});

test('equipment manager can view commitment details for club equipment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
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

    Livewire::test(ClubEquipmentList::class)
        ->call('openDetailsModal', $commitment->id)
        ->assertSet('showDetailsModal', true)
        ->assertSet('detailCommitment.id', $commitment->id);
});

test('regular user cannot view commitment details for club equipment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('openDetailsModal', $commitment->id)
        ->assertSet('showDetailsModal', false)
        ->assertDispatched('notify');
});

test('equipment manager can change commitment status for club equipment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('changeStatus', $commitment->id, 'delivered')
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'delivered',
    ]);
});

test('regular user cannot change commitment status for club equipment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('changeStatus', $commitment->id, 'delivered')
        ->assertForbidden();
});

test('equipment manager can update delivery notes for club equipment commitment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
        'delivery_notes' => 'Original notes',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('updateNotes', $commitment->id, 'Updated notes')
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'delivery_notes' => 'Updated notes',
    ]);
});

test('regular user cannot update notes for club equipment commitment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
        'delivery_notes' => 'Original notes',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('updateNotes', $commitment->id, 'Hacked notes')
        ->assertForbidden();
});

test('equipment manager can bulk commit club equipment to event', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);
    $eq2 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    Livewire::test(ClubEquipmentList::class)
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

test('bulk commit aborts if any club equipment has overlapping commitment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);
    $eq2 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    $overlapping = Event::factory()->create([
        'start_time' => now()->addDays(8),
        'end_time' => now()->addDays(10),
    ]);
    EquipmentEvent::factory()->create([
        'equipment_id' => $eq2->id,
        'event_id' => $overlapping->id,
        'status' => 'committed',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->set('selectedIds', [$eq1->id, $eq2->id])
        ->set('bulkCommitEventId', $event->id)
        ->call('bulkCommitEquipment')
        ->assertHasErrors(['bulkCommit']);

    $this->assertDatabaseMissing('equipment_event', [
        'equipment_id' => $eq1->id,
        'event_id' => $event->id,
    ]);
});

test('equipment manager can bulk delete club equipment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);
    $eq2 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->set('selectedIds', [$eq1->id, $eq2->id])
        ->call('bulkDeleteEquipment')
        ->assertDispatched('notify');

    expect(Equipment::where('id', $eq1->id)->exists())->toBeFalse();
    expect(Equipment::where('id', $eq2->id)->exists())->toBeFalse();
});

test('regular user cannot bulk delete club equipment', function () {
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->set('selectedIds', [$eq1->id])
        ->call('bulkDeleteEquipment')
        ->assertForbidden();

    expect(Equipment::where('id', $eq1->id)->exists())->toBeTrue();
});

test('equipment manager can open notes modal for club equipment commitment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => Event::factory()->create()->id,
        'status' => 'committed',
        'delivery_notes' => 'Existing notes',
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('openNotesModal', $commitment->id)
        ->assertSet('showNotesModal', true)
        ->assertSet('updateNoteId', $commitment->id)
        ->assertSet('tempNotes', 'Existing notes');
});

test('select all and deselect all work for club equipment', function () {
    giveEquipmentManagerRole($this->user);
    $this->actingAs($this->user);

    $eq1 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);
    $eq2 = Equipment::factory()->create([
        'owner_organization_id' => $this->organization->id,
        'owner_user_id' => null,
    ]);

    Livewire::test(ClubEquipmentList::class)
        ->call('selectAll')
        ->assertSet('selectedIds', [$eq1->id, $eq2->id])
        ->call('deselectAll')
        ->assertSet('selectedIds', []);
});
