<?php

use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);
    $this->seed(\Database\Seeders\RoleSeeder::class);
});

test('event manager role has contact logging permissions', function () {
    $eventManager = Role::findByName('Event Manager');

    expect($eventManager->hasPermissionTo('log-contacts'))->toBeTrue()
        ->and($eventManager->hasPermissionTo('edit-contacts'))->toBeTrue();
});

test('operator role has log contacts permission', function () {
    $operator = Role::findByName('Operator');

    expect($operator->hasPermissionTo('log-contacts'))->toBeTrue()
        ->and($operator->hasPermissionTo('edit-contacts'))->toBeFalse();
});

test('station captain role has both contact permissions', function () {
    $stationCaptain = Role::findByName('Station Captain');

    expect($stationCaptain->hasPermissionTo('log-contacts'))->toBeTrue()
        ->and($stationCaptain->hasPermissionTo('edit-contacts'))->toBeTrue();
});

test('system administrator role has all permissions including contact logging', function () {
    $systemAdmin = Role::findByName('System Administrator');

    expect($systemAdmin->hasPermissionTo('log-contacts'))->toBeTrue()
        ->and($systemAdmin->hasPermissionTo('edit-contacts'))->toBeTrue();
});
