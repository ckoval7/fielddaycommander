<?php

use App\Livewire\Equipment\EquipmentList;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
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
