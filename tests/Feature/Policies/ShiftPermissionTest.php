<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'PermissionSeeder']);
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true']
    );
});

test('system administrator has manage-shifts permission', function () {
    $user = User::factory()->create();
    $user->assignRole('System Administrator');
    expect($user->can('manage-shifts'))->toBeTrue();
});

test('event manager has manage-shifts permission', function () {
    $user = User::factory()->create();
    $user->assignRole('Event Manager');
    expect($user->can('manage-shifts'))->toBeTrue();
});

test('operator does not have manage-shifts permission', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');
    expect($user->can('manage-shifts'))->toBeFalse();
});
