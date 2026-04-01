<?php

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventType;
use App\Models\User;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

it('has a bonusType relationship', function () {
    $eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);

    $bonusType = BonusType::where('event_type_id', $eventType->id)->first()
        ?? BonusType::factory()->create(['event_type_id' => $eventType->id]);

    $bonus = EventBonus::factory()->create(['bonus_type_id' => $bonusType->id]);

    expect($bonus->bonusType)->not->toBeNull();
    expect($bonus->bonusType->id)->toBe($bonusType->id);
});

test('it has a claimedBy relationship', function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $user = User::factory()->create();
    $bonus = EventBonus::factory()->create(['claimed_by_user_id' => $user->id]);

    expect($bonus->claimedBy)->toBeInstanceOf(User::class)
        ->and($bonus->claimedBy->id)->toBe($user->id);
});

test('it has a verifiedBy relationship', function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $user = User::factory()->create();
    $bonus = EventBonus::factory()->create([
        'verified_by_user_id' => $user->id,
        'is_verified' => true,
        'verified_at' => now(),
    ]);

    expect($bonus->verifiedBy)->toBeInstanceOf(User::class)
        ->and($bonus->verifiedBy->id)->toBe($user->id);
});
