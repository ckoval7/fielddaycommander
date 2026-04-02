<?php

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;
use App\Policies\EquipmentPolicy;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::findOrCreate('manage-own-equipment', 'web');
    Permission::findOrCreate('edit-any-equipment', 'web');
    Permission::findOrCreate('manage-event-equipment', 'web');

    $this->policy = new EquipmentPolicy;
});

describe('viewAny', function () {
    it('allows any authenticated user to view any equipment', function () {
        $user = User::factory()->create();

        expect($this->policy->viewAny($user))->toBeTrue();
    });
});

describe('view', function () {
    it('allows any authenticated user to view equipment', function () {
        $user = User::factory()->create();
        $equipment = Equipment::factory()->create();

        expect($this->policy->view($user, $equipment))->toBeTrue();
    });
});

describe('create', function () {
    it('allows users with manage-own-equipment permission to create', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('manage-own-equipment');

        expect($this->policy->create($user))->toBeTrue();
    });

    it('allows users with edit-any-equipment permission to create', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('edit-any-equipment');

        expect($this->policy->create($user))->toBeTrue();
    });

    it('denies users without either permission from creating', function () {
        $user = User::factory()->create();

        expect($this->policy->create($user))->toBeFalse();
    });
});

describe('update', function () {
    it('allows users with edit-any-equipment to update any equipment', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);

        $editor = User::factory()->create();
        $editor->givePermissionTo('edit-any-equipment');

        expect($this->policy->update($editor, $equipment))->toBeTrue();
    });

    it('allows the owner with manage-own-equipment to update their equipment', function () {
        $owner = User::factory()->create();
        $owner->givePermissionTo('manage-own-equipment');
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);

        expect($this->policy->update($owner, $equipment))->toBeTrue();
    });

    it('denies the owner without manage-own-equipment from updating their equipment', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);

        expect($this->policy->update($owner, $equipment))->toBeFalse();
    });

    it('denies a non-owner with manage-own-equipment from updating others equipment', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);

        $otherUser = User::factory()->create();
        $otherUser->givePermissionTo('manage-own-equipment');

        expect($this->policy->update($otherUser, $equipment))->toBeFalse();
    });

    it('denies users without any permission from updating', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);

        $user = User::factory()->create();

        expect($this->policy->update($user, $equipment))->toBeFalse();
    });
});

describe('delete', function () {
    it('allows users with edit-any-equipment to delete any equipment', function () {
        $equipment = Equipment::factory()->create();
        $user = User::factory()->create();
        $user->givePermissionTo('edit-any-equipment');

        expect($this->policy->delete($user, $equipment))->toBeTrue();
    });

    it('allows the owner with manage-own-equipment to delete their equipment', function () {
        $owner = User::factory()->create();
        $owner->givePermissionTo('manage-own-equipment');
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);

        expect($this->policy->delete($owner, $equipment))->toBeTrue();
    });

    it('denies users without permission from deleting', function () {
        $equipment = Equipment::factory()->create();
        $user = User::factory()->create();

        expect($this->policy->delete($user, $equipment))->toBeFalse();
    });
});

describe('restore', function () {
    it('allows users with edit-any-equipment to restore any equipment', function () {
        $equipment = Equipment::factory()->create();
        $equipment->delete();

        $user = User::factory()->create();
        $user->givePermissionTo('edit-any-equipment');

        expect($this->policy->restore($user, $equipment))->toBeTrue();
    });

    it('allows the owner with manage-own-equipment to restore their equipment', function () {
        $owner = User::factory()->create();
        $owner->givePermissionTo('manage-own-equipment');
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
        $equipment->delete();

        expect($this->policy->restore($owner, $equipment))->toBeTrue();
    });

    it('denies users without permission from restoring', function () {
        $equipment = Equipment::factory()->create();
        $equipment->delete();

        $user = User::factory()->create();

        expect($this->policy->restore($user, $equipment))->toBeFalse();
    });
});

describe('forceDelete', function () {
    it('allows users with edit-any-equipment to force delete equipment with no active commitments', function () {
        $equipment = Equipment::factory()->create();
        $user = User::factory()->create();
        $user->givePermissionTo('edit-any-equipment');

        expect($this->policy->forceDelete($user, $equipment))->toBeTrue();
    });

    it('denies users without edit-any-equipment from force deleting', function () {
        $owner = User::factory()->create();
        $owner->givePermissionTo('manage-own-equipment');
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);

        expect($this->policy->forceDelete($owner, $equipment))->toBeFalse();
    });

    it('denies force delete when equipment has a committed status commitment', function () {
        $equipment = Equipment::factory()->create();
        EquipmentEvent::factory()->create(['equipment_id' => $equipment->id, 'status' => 'committed']);

        $user = User::factory()->create();
        $user->givePermissionTo('edit-any-equipment');

        expect($this->policy->forceDelete($user, $equipment))->toBeFalse();
    });

    it('denies force delete when equipment has a delivered status commitment', function () {
        $equipment = Equipment::factory()->create();
        EquipmentEvent::factory()->delivered()->create(['equipment_id' => $equipment->id]);

        $user = User::factory()->create();
        $user->givePermissionTo('edit-any-equipment');

        expect($this->policy->forceDelete($user, $equipment))->toBeFalse();
    });

    it('allows force delete when equipment only has cancelled commitments', function () {
        $equipment = Equipment::factory()->create();
        EquipmentEvent::factory()->cancelled()->create(['equipment_id' => $equipment->id]);

        $user = User::factory()->create();
        $user->givePermissionTo('edit-any-equipment');

        expect($this->policy->forceDelete($user, $equipment))->toBeTrue();
    });

    it('allows force delete when equipment only has returned commitments', function () {
        $equipment = Equipment::factory()->create();
        EquipmentEvent::factory()->returned()->create(['equipment_id' => $equipment->id]);

        $user = User::factory()->create();
        $user->givePermissionTo('edit-any-equipment');

        expect($this->policy->forceDelete($user, $equipment))->toBeTrue();
    });
});

describe('commit', function () {
    it('allows the equipment owner to commit their equipment', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);

        expect($this->policy->commit($owner, $equipment))->toBeTrue();
    });

    it('allows users with manage-event-equipment to commit any equipment', function () {
        $equipment = Equipment::factory()->create();
        $user = User::factory()->create();
        $user->givePermissionTo('manage-event-equipment');

        expect($this->policy->commit($user, $equipment))->toBeTrue();
    });

    it('denies non-owners without manage-event-equipment from committing equipment', function () {
        $equipment = Equipment::factory()->create();
        $user = User::factory()->create();

        expect($this->policy->commit($user, $equipment))->toBeFalse();
    });
});

describe('changeStatus', function () {
    it('allows users with manage-event-equipment to change any status', function () {
        $equipment = Equipment::factory()->create();
        $equipmentEvent = EquipmentEvent::factory()->delivered()->create(['equipment_id' => $equipment->id]);

        $user = User::factory()->create();
        $user->givePermissionTo('manage-event-equipment');

        expect($this->policy->changeStatus($user, $equipmentEvent))->toBeTrue();
    });

    it('allows the equipment owner to change status when commitment is committed', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
        $equipmentEvent = EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'status' => 'committed',
        ]);

        expect($this->policy->changeStatus($owner, $equipmentEvent))->toBeTrue();
    });

    it('denies the equipment owner from changing status when commitment is not committed', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
        $equipmentEvent = EquipmentEvent::factory()->delivered()->create(['equipment_id' => $equipment->id]);

        expect($this->policy->changeStatus($owner, $equipmentEvent))->toBeFalse();
    });

    it('denies non-owners without manage-event-equipment from changing status', function () {
        $equipment = Equipment::factory()->create();
        $equipmentEvent = EquipmentEvent::factory()->create(['equipment_id' => $equipment->id]);

        $user = User::factory()->create();

        expect($this->policy->changeStatus($user, $equipmentEvent))->toBeFalse();
    });

    it('denies the owner from changing status of returned commitment', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
        $equipmentEvent = EquipmentEvent::factory()->returned()->create(['equipment_id' => $equipment->id]);

        expect($this->policy->changeStatus($owner, $equipmentEvent))->toBeFalse();
    });

    it('denies the owner from changing status of cancelled commitment', function () {
        $owner = User::factory()->create();
        $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
        $equipmentEvent = EquipmentEvent::factory()->cancelled()->create(['equipment_id' => $equipment->id]);

        expect($this->policy->changeStatus($owner, $equipmentEvent))->toBeFalse();
    });
});
