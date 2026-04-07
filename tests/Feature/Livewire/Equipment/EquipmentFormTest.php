<?php

use App\Livewire\Equipment\EquipmentForm;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('public');

    $this->user = User::factory()->create();

    // Create permissions
    Permission::create(['name' => 'manage-own-equipment']);
    Permission::create(['name' => 'edit-any-equipment']);

    $role = Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('manage-own-equipment');
    $this->user->assignRole($role);
});

test('photo upload shows validation feedback immediately for valid image', function () {
    $this->actingAs($this->user);

    $file = UploadedFile::fake()->image('equipment.jpg', 800, 600)->size(1000);

    // Create mode - explicitly pass null for equipment
    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->set('photo', $file)
        ->assertHasNoErrors('photo');
});

test('photo upload validates file type and shows error for non-image', function () {
    $this->actingAs($this->user);

    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->set('photo', $file)
        ->assertHasErrors('photo');
});

test('photo upload validates file size and shows error for large files', function () {
    $this->actingAs($this->user);

    // Create a file larger than 5MB (5120KB)
    $file = UploadedFile::fake()->image('equipment.jpg')->size(6000);

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->set('photo', $file)
        ->assertHasErrors('photo');
});

test('photo upload stores file and creates equipment', function () {
    $this->actingAs($this->user);

    $file = UploadedFile::fake()->image('equipment.jpg');

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->set('make', 'Yaesu')
        ->set('model', 'FT-891')
        ->set('type', 'radio')
        ->set('photo', $file)
        ->call('save');

    $equipment = Equipment::where('make', 'Yaesu')
        ->where('model', 'FT-891')
        ->first();

    expect($equipment)->not->toBeNull()
        ->and($equipment->photo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($equipment->photo_path);
});

test('photo upload replaces existing photo when updating equipment', function () {
    $this->actingAs($this->user);

    // Create equipment with existing photo
    $oldFile = UploadedFile::fake()->image('old-photo.jpg');
    $oldPath = $oldFile->store('equipment-photos', 'public');

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
        'photo_path' => $oldPath,
    ]);

    Storage::disk('public')->put($oldPath, 'old content');

    // Upload new photo
    $newFile = UploadedFile::fake()->image('new-photo.jpg');

    Livewire::test(EquipmentForm::class, ['equipment' => $equipment])
        ->set('photo', $newFile)
        ->call('save');

    $equipment->refresh();

    // Old photo should be deleted
    Storage::disk('public')->assertMissing($oldPath);

    // New photo should exist
    Storage::disk('public')->assertExists($equipment->photo_path);
    expect($equipment->photo_path)->not->toBe($oldPath);
});

test('equipment form can be created without photo', function () {
    $this->actingAs($this->user);

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->set('make', 'Icom')
        ->set('model', 'IC-7300')
        ->set('type', 'radio')
        ->call('save');

    $equipment = Equipment::where('make', 'Icom')
        ->where('model', 'IC-7300')
        ->first();

    expect($equipment)->not->toBeNull()
        ->and($equipment->photo_path)->toBeNull();
});

test('manager with edit-any-equipment can create equipment for another user', function () {
    $manager = User::factory()->create();
    $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
    Permission::findOrCreate('view-all-equipment');
    $managerRole->givePermissionTo(['manage-own-equipment', 'edit-any-equipment', 'view-all-equipment']);
    $manager->assignRole($managerRole);

    $targetUser = User::factory()->create([
        'call_sign' => 'W1BOB',
        'first_name' => 'Bob',
        'last_name' => 'Smith',
    ]);

    $this->actingAs($manager);

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->set('owner_user_id', $targetUser->id)
        ->set('make', 'Kenwood')
        ->set('model', 'TS-590SG')
        ->set('type', 'radio')
        ->call('save');

    $equipment = Equipment::where('make', 'Kenwood')
        ->where('model', 'TS-590SG')
        ->first();

    expect($equipment)->not->toBeNull()
        ->and($equipment->owner_user_id)->toBe($targetUser->id);
});

test('for_user query param pre-selects owner_user_id', function () {
    $manager = User::factory()->create();
    $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
    Permission::findOrCreate('view-all-equipment');
    $managerRole->givePermissionTo(['manage-own-equipment', 'edit-any-equipment', 'view-all-equipment']);
    $manager->assignRole($managerRole);

    $targetUser = User::factory()->create();

    $this->actingAs($manager);

    Livewire::withQueryParams(['for_user' => $targetUser->id])
        ->test(EquipmentForm::class, ['equipment' => null])
        ->assertSet('owner_user_id', $targetUser->id);
});

test('regular user cannot create equipment owned by another user', function () {
    $this->actingAs($this->user);

    $otherUser = User::factory()->create();

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->set('owner_user_id', $otherUser->id)
        ->set('make', 'Yaesu')
        ->set('model', 'FT-891')
        ->set('type', 'radio')
        ->call('save');

    $equipment = Equipment::where('make', 'Yaesu')
        ->where('model', 'FT-891')
        ->first();

    expect($equipment)->not->toBeNull()
        ->and($equipment->owner_user_id)->toBe($this->user->id);
});

test('owner dropdown is visible for managers creating personal equipment', function () {
    $manager = User::factory()->create();
    $managerRole = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
    Permission::findOrCreate('view-all-equipment');
    $managerRole->givePermissionTo(['manage-own-equipment', 'edit-any-equipment', 'view-all-equipment']);
    $manager->assignRole($managerRole);

    $this->actingAs($manager);

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->assertSee('Owner')
        ->assertSee('Select equipment owner');
});

test('owner dropdown is hidden for regular users', function () {
    $this->actingAs($this->user);

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->assertDontSee('Select equipment owner');
});

describe('audit logging', function () {
    test('creating equipment logs to audit log', function () {
        $this->actingAs($this->user);

        Livewire::test(EquipmentForm::class, ['equipment' => null])
            ->set('make', 'Kenwood')
            ->set('model', 'TS-590SG')
            ->set('type', 'radio')
            ->call('save');

        $equipment = Equipment::where('make', 'Kenwood')->where('model', 'TS-590SG')->first();

        $auditLog = AuditLog::where('action', 'equipment.created')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->user->id);
        expect($auditLog->auditable_type)->toBe(Equipment::class);
        expect($auditLog->auditable_id)->toBe($equipment->id);
        expect($auditLog->new_values)->toMatchArray([
            'make' => 'Kenwood',
            'model' => 'TS-590SG',
            'type' => 'radio',
        ]);
    });

    test('updating equipment logs old and new values', function () {
        $this->actingAs($this->user);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => $this->user->id,
            'make' => 'Kenwood',
            'model' => 'TS-590SG',
            'type' => 'radio',
        ]);

        Livewire::test(EquipmentForm::class, ['equipment' => $equipment])
            ->set('make', 'Icom')
            ->set('model', 'IC-7300')
            ->call('save');

        $auditLog = AuditLog::where('action', 'equipment.updated')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values)->toMatchArray([
            'make' => 'Kenwood',
            'model' => 'TS-590SG',
        ]);
        expect($auditLog->new_values)->toMatchArray([
            'make' => 'Icom',
            'model' => 'IC-7300',
        ]);
    });
});
