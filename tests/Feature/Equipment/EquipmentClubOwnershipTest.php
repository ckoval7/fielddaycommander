<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::create(['name' => 'edit-any-equipment']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('edit-any-equipment');

    $this->organization = Organization::factory()->create(['name' => 'Test Club']);
    Setting::set('default_organization_id', $this->organization->id);
});

it('equipment model stores club equipment with organization owner', function () {
    // Test the model logic directly
    $equipment = Equipment::create([
        'owner_user_id' => null,
        'owner_organization_id' => $this->organization->id,
        'make' => 'Test Make',
        'model' => 'Test Model',
        'type' => 'radio',
    ]);

    expect($equipment->owner_user_id)->toBeNull()
        ->and($equipment->owner_organization_id)->toBe($this->organization->id)
        ->and($equipment->owner_name)->toBe('Club Equipment')
        ->and($equipment->is_club_equipment)->toBeTrue();
});

it('equipment model stores personal equipment with user owner', function () {
    // Test the model logic directly
    $equipment = Equipment::create([
        'owner_user_id' => $this->user->id,
        'owner_organization_id' => null,
        'make' => 'Personal Make',
        'model' => 'Personal Model',
        'type' => 'radio',
    ]);

    expect($equipment->owner_user_id)->toBe($this->user->id)
        ->and($equipment->owner_organization_id)->toBeNull()
        ->and($equipment->is_club_equipment)->toBeFalse();
});
