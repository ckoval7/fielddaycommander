<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Equipment;
use App\Models\Organization;

// Relationship Tests
test('organization has many equipment', function () {
    $organization = Organization::factory()->create();

    $equipment1 = Equipment::factory()->create([
        'owner_organization_id' => $organization->id,
        'owner_user_id' => null,
    ]);

    $equipment2 = Equipment::factory()->create([
        'owner_organization_id' => $organization->id,
        'owner_user_id' => null,
    ]);

    expect($organization->equipment)->toHaveCount(2);
    expect($organization->equipment->pluck('id')->toArray())->toContain($equipment1->id, $equipment2->id);
});

// Scope Tests
test('active scope returns only active organizations', function () {
    Organization::factory()->create(['is_active' => true, 'name' => 'Active Org']);
    Organization::factory()->create(['is_active' => false, 'name' => 'Inactive Org']);

    $activeOrgs = Organization::active()->get();

    expect($activeOrgs)->toHaveCount(1);
    expect($activeOrgs->first()->name)->toBe('Active Org');
    expect($activeOrgs->first()->is_active)->toBeTrue();
});

// Soft Delete Tests
test('organization can be soft deleted', function () {
    $organization = Organization::factory()->create(['name' => 'Test Org']);

    $organization->delete();

    expect(Organization::withTrashed()->where('name', 'Test Org')->exists())->toBeTrue();
    expect(Organization::where('name', 'Test Org')->exists())->toBeFalse();
});

test('soft deleted organization can be restored', function () {
    $organization = Organization::factory()->create(['name' => 'Test Org']);
    $organization->delete();

    $organization->restore();

    expect(Organization::where('name', 'Test Org')->exists())->toBeTrue();
    expect($organization->deleted_at)->toBeNull();
});

test('soft deleted organization is excluded from queries by default', function () {
    Organization::factory()->create(['name' => 'Active Org']);
    $deletedOrg = Organization::factory()->create(['name' => 'Deleted Org']);
    $deletedOrg->delete();

    $organizations = Organization::all();

    expect($organizations)->toHaveCount(1);
    expect($organizations->first()->name)->toBe('Active Org');
});
