<?php

use App\Livewire\Equipment\AllEquipmentList;
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

    Permission::create(['name' => 'manage-own-equipment']);
    Permission::create(['name' => 'view-all-equipment']);
    Permission::create(['name' => 'edit-any-equipment']);

    $role = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(['manage-own-equipment', 'view-all-equipment']);
    $this->user->assignRole($role);
});

test('all equipment list requires authentication', function () {
    Livewire::test(AllEquipmentList::class)
        ->assertForbidden();
});

test('all equipment list requires view-all-equipment permission', function () {
    $basicUser = User::factory()->create();
    $basicRole = Role::create(['name' => 'Basic', 'guard_name' => 'web']);
    $basicUser->assignRole($basicRole);

    $this->actingAs($basicUser);

    Livewire::test(AllEquipmentList::class)
        ->assertForbidden();
});

test('all equipment list is accessible with view-all-equipment permission', function () {
    $this->actingAs($this->user);

    Livewire::test(AllEquipmentList::class)
        ->assertStatus(200);
});

test('all equipment list shows all users equipment by default', function () {
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

    Livewire::test(AllEquipmentList::class)
        ->assertSee('Yaesu')
        ->assertSee('FT-991A')
        ->assertSee('Icom')
        ->assertSee('IC-7300');
});

test('all equipment list filters by specific user', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
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

    Livewire::test(AllEquipmentList::class)
        ->set('userFilter', $otherUser->id)
        ->assertSee('Icom')
        ->assertSee('IC-7300')
        ->assertDontSee('Yaesu')
        ->assertDontSee('FT-991A');
});

test('all equipment list filters by club equipment', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);

    $org = Organization::factory()->create();
    Equipment::factory()->create([
        'owner_organization_id' => $org->id,
        'owner_user_id' => null,
        'make' => 'Kenwood',
        'model' => 'TS-990S',
    ]);

    Livewire::test(AllEquipmentList::class)
        ->set('userFilter', 'club')
        ->assertSee('Kenwood')
        ->assertSee('TS-990S')
        ->assertDontSee('Yaesu')
        ->assertDontSee('FT-991A');
});

test('all equipment list search works', function () {
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

    Livewire::test(AllEquipmentList::class)
        ->set('search', 'Yaesu')
        ->assertSee('Yaesu')
        ->assertDontSee('Icom');
});

test('all equipment list type filter works', function () {
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

    Livewire::test(AllEquipmentList::class)
        ->set('typeFilter', 'radio')
        ->assertSee('Yaesu')
        ->assertDontSee('Diamond');
});

test('all equipment list shows owner column', function () {
    $this->actingAs($this->user);

    Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
    ]);

    Livewire::test(AllEquipmentList::class)
        ->assertSee('Owner');
});

test('all equipment list user filter options include only users with equipment', function () {
    $this->actingAs($this->user);

    $userWithEquipment = User::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'call_sign' => 'W1JD',
    ]);
    Equipment::factory()->create(['owner_user_id' => $userWithEquipment->id]);

    $userWithoutEquipment = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'call_sign' => 'W2JS',
    ]);

    $component = Livewire::test(AllEquipmentList::class);
    $userOptions = $component->get('userOptions');

    $userIds = collect($userOptions)->pluck('id')->toArray();
    expect($userIds)->toContain($userWithEquipment->id);
    expect($userIds)->not->toContain($userWithoutEquipment->id);
});

test('all equipment list paginates results', function () {
    $this->actingAs($this->user);

    Equipment::factory()->count(30)->create([
        'owner_user_id' => $this->user->id,
    ]);

    $component = Livewire::test(AllEquipmentList::class);
    $equipment = $component->get('equipment');
    expect($equipment->total())->toBe(30);
    expect($equipment->perPage())->toBe(25);
});

test('all equipment list shows committed status for equipment with active commitment', function () {
    $this->actingAs($this->user);

    $committedEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Icom',
        'model' => 'Committed Radio',
    ]);

    $availableEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'make' => 'Yaesu',
        'model' => 'Available Radio',
    ]);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => $committedEquipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Livewire::test(AllEquipmentList::class)
        ->assertSeeInOrder(['Committed Radio', 'Committed'])
        ->assertSeeInOrder(['Available Radio', 'Available']);
});

test('setting user filter resets pagination', function () {
    $this->actingAs($this->user);

    Equipment::factory()->count(30)->create([
        'owner_user_id' => $this->user->id,
    ]);

    $component = Livewire::test(AllEquipmentList::class)
        ->set('userFilter', $this->user->id);

    expect($component->get('userFilter'))->toBe($this->user->id);
});

test('add equipment for user button is visible to users with edit-any-equipment permission', function () {
    $manager = User::factory()->create();
    $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
    $managerRole->givePermissionTo(['manage-own-equipment', 'view-all-equipment', 'edit-any-equipment']);
    $manager->assignRole($managerRole);

    $this->actingAs($manager);

    Livewire::test(AllEquipmentList::class)
        ->assertSee('Add Equipment for User');
});

test('add equipment for user button is hidden from users without edit-any-equipment permission', function () {
    $this->actingAs($this->user);

    Livewire::test(AllEquipmentList::class)
        ->assertDontSee('Add Equipment for User');
});

// --- Management actions ---

function makeAllEquipmentManager(): User
{
    $manager = User::factory()->create();
    $managerRole = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
    $managerRole->givePermissionTo(['manage-own-equipment', 'view-all-equipment', 'edit-any-equipment']);
    $manager->assignRole($managerRole);

    return $manager;
}

test('equipment manager sees action menu with edit link for any user equipment', function () {
    $this->actingAs(makeAllEquipmentManager());

    $equipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(AllEquipmentList::class)
        ->assertSee('Actions for '.$equipment->make)
        ->assertSee(route('equipment.edit', $equipment), false)
        ->assertSee('Commit to Event');
});

test('viewer without edit-any-equipment does not see action menu', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(AllEquipmentList::class)
        ->assertDontSee('Actions for '.$equipment->make)
        ->assertDontSee(route('equipment.edit', $equipment), false);
});

test('equipment manager can delete another users equipment', function () {
    $this->actingAs(makeAllEquipmentManager());

    $equipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(AllEquipmentList::class)
        ->call('deleteEquipment', $equipment->id)
        ->assertDispatched('notify');

    expect(Equipment::find($equipment->id))->toBeNull();
});

test('viewer cannot delete another users equipment', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(AllEquipmentList::class)
        ->call('deleteEquipment', $equipment->id)
        ->assertForbidden();

    expect(Equipment::find($equipment->id))->not->toBeNull();
});

test('equipment manager can change commitment status for any user equipment', function () {
    $this->actingAs(makeAllEquipmentManager());

    $equipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);
    $event = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Livewire::test(AllEquipmentList::class)
        ->assertSee('View Commitment')
        ->call('changeStatus', $commitment->id, 'delivered')
        ->assertDispatched('notify');

    expect($commitment->fresh()->status)->toBe('delivered');
});

test('viewer cannot change commitment status', function () {
    $this->actingAs($this->user);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);
    $event = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Livewire::test(AllEquipmentList::class)
        ->call('changeStatus', $commitment->id, 'delivered')
        ->assertForbidden();

    expect($commitment->fresh()->status)->toBe('committed');
});

test('equipment manager can commit another users equipment to an event', function () {
    $this->actingAs(makeAllEquipmentManager());

    $equipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);
    $event = Event::factory()->create([
        'start_time' => now()->addDays(5),
        'end_time' => now()->addDays(6),
    ]);

    Livewire::test(AllEquipmentList::class)
        ->call('openCommitModal', $equipment->id)
        ->assertSet('showCommitModal', true)
        ->set('commitEventId', $event->id)
        ->call('commitEquipment')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);
});

test('AllEquipmentList ignores sort columns outside the allowlist', function () {
    $this->actingAs($this->user);

    Livewire::test(AllEquipmentList::class)
        ->set('sortBy', 'nonexistent_column')
        ->set('sortDirection', 'sideways')
        ->assertOk();
});

test('the photo viewer title is escaped exactly once', function () {
    $this->actingAs($this->user);

    Livewire::test(AllEquipmentList::class)
        ->call('viewPhoto', 'equipment/a.jpg', "O'Brien <b>Radio</b>")
        ->assertSeeHtml('O&#039;Brien &lt;b&gt;Radio&lt;/b&gt;')
        ->assertDontSeeHtml('&amp;#039;')
        ->assertDontSeeHtml('&amp;lt;');
});
