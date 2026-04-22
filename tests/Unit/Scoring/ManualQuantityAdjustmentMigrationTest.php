<?php

use App\Models\EventBonus;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
});

test('manual_quantity_adjustment round-trips through the factory', function () {
    $bonus = EventBonus::factory()->create(['manual_quantity_adjustment' => 2]);

    expect($bonus->fresh()->manual_quantity_adjustment)->toBe(2);
});

test('manual_quantity_adjustment defaults to null', function () {
    $bonus = EventBonus::factory()->create();

    expect($bonus->fresh()->manual_quantity_adjustment)->toBeNull();
});
