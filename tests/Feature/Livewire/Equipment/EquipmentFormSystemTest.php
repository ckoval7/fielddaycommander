<?php

use App\Livewire\Equipment\EquipmentForm;
use App\Models\Equipment;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'manage-own-equipment']);
});

test('system user cannot create equipment', function () {
    $systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);
    $systemUser->givePermissionTo('manage-own-equipment');

    $this->actingAs($systemUser);

    Livewire::test(EquipmentForm::class, ['equipment' => null])
        ->set('owner_user_id', $systemUser->id)
        ->set('make', 'Icom')
        ->set('model', 'IC-7300')
        ->set('type', 'transceiver')
        ->set('description', 'Test radio')
        ->call('save')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'] ?? '', 'SYSTEM account'));

    expect(Equipment::count())->toBe(0);
});
