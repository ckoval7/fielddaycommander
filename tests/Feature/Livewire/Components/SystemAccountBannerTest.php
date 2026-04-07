<?php

use App\Livewire\Components\SystemAccountBanner;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('banner is visible when logged in as SYSTEM account', function () {
    $systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);
    Permission::firstOrCreate(['name' => 'manage-users']);
    $systemUser->givePermissionTo('manage-users');

    $this->actingAs($systemUser);

    Livewire::test(SystemAccountBanner::class)
        ->assertSee('SYSTEM account')
        ->assertSee('configuration only')
        ->assertSeeHtml('href');
});

test('banner is not visible for regular users', function () {
    $user = User::factory()->create([
        'call_sign' => 'W1ABC',
    ]);

    $this->actingAs($user);

    Livewire::test(SystemAccountBanner::class)
        ->assertDontSee('SYSTEM account');
});
