<?php

use App\Models\Message;
use App\Models\User;
use App\Models\W1awBulletin;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
|--------------------------------------------------------------------------
| MessagePolicy and W1awBulletinPolicy (via OwnerOrManagerPolicy)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    $this->owner = User::factory()->create();
    $this->owner->assignRole('Operator');
});

dataset('owned models', [
    'message' => [Message::class],
    'w1aw bulletin' => [W1awBulletin::class],
]);

dataset('owner actions', ['update', 'delete']);

test('owner with log-contacts can modify their own record', function (string $modelClass, string $ability) {
    $record = $modelClass::factory()->create(['user_id' => $this->owner->id]);

    expect($this->owner->can($ability, $record))->toBeTrue();
})->with('owned models')->with('owner actions');

test('another operator cannot modify someone else\'s record', function (string $modelClass, string $ability) {
    $record = $modelClass::factory()->create(['user_id' => $this->owner->id]);
    $otherOperator = User::factory()->create();
    $otherOperator->assignRole('Operator');

    expect($otherOperator->can($ability, $record))->toBeFalse();
})->with('owned models')->with('owner actions');

test('owner without log-contacts cannot modify their own record', function (string $modelClass, string $ability) {
    $userWithoutPermissions = User::factory()->create();
    $record = $modelClass::factory()->create(['user_id' => $userWithoutPermissions->id]);

    expect($userWithoutPermissions->can($ability, $record))->toBeFalse();
})->with('owned models')->with('owner actions');

test('manager can modify any record', function (string $modelClass, string $ability) {
    $record = $modelClass::factory()->create(['user_id' => $this->owner->id]);
    $manager = User::factory()->create();
    $manager->assignRole('Event Manager');

    expect($manager->can($ability, $record))->toBeTrue();
})->with('owned models')->with('owner actions');

test('only users with log-contacts can create records', function (string $modelClass) {
    expect($this->owner->can('create', $modelClass))->toBeTrue();
    expect(User::factory()->create()->can('create', $modelClass))->toBeFalse();
})->with('owned models');

test('any authenticated user can view records', function (string $modelClass) {
    $record = $modelClass::factory()->create(['user_id' => $this->owner->id]);
    $viewer = User::factory()->create();

    expect($viewer->can('viewAny', $modelClass))->toBeTrue();
    expect($viewer->can('view', $record))->toBeTrue();
})->with('owned models');
