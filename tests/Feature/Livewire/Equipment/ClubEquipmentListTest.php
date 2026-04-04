<?php

use App\Livewire\Equipment\ClubEquipmentList;
use App\Models\Equipment;
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
