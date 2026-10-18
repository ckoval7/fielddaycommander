<?php

use App\Models\EventBonus;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Insert a raw event_bonuses row sharing $base's claim key.
 *
 * @param  array<string, mixed>  $attributes
 */
function insertBonusCopy(EventBonus $base, array $attributes): int
{
    return DB::table('event_bonuses')->insertGetId(array_merge([
        'event_configuration_id' => $base->event_configuration_id,
        'bonus_type_id' => $base->bonus_type_id,
        'claimed_by_user_id' => null,
        'quantity' => 1,
        'calculated_points' => 100,
        'notes' => null,
        'proof_file_path' => null,
        'is_verified' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_17_175302_add_unique_bonus_type_index_to_event_bonuses_table.php');
    $this->migration->down();
});

test('keeps the verified row over an older unverified one', function () {
    $verifier = User::factory()->create();
    $oldest = EventBonus::factory()->create(['claimed_by_user_id' => null, 'notes' => null]);
    $verifiedId = insertBonusCopy($oldest, [
        'is_verified' => true,
        'verified_by_user_id' => $verifier->id,
        'verified_at' => now(),
    ]);

    $this->migration->up();

    $rows = DB::table('event_bonuses')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($verifiedId)
        ->and((bool) $rows->first()->is_verified)->toBeTrue();
});

test('keeps the row with notes and a claimant, then fills gaps from the removed rows', function () {
    $claimant = User::factory()->create();
    $bare = EventBonus::factory()->create(['claimed_by_user_id' => null, 'notes' => null]);
    $withNotesId = insertBonusCopy($bare, ['notes' => 'Sent to section manager', 'claimed_by_user_id' => $claimant->id]);
    insertBonusCopy($bare, ['proof_file_path' => 'bonus-proofs/photo.jpg', 'updated_at' => now()->addMinute()]);

    $this->migration->up();

    $rows = DB::table('event_bonuses')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($withNotesId)
        ->and($rows->first()->notes)->toBe('Sent to section manager')
        ->and((int) $rows->first()->claimed_by_user_id)->toBe($claimant->id)
        ->and($rows->first()->proof_file_path)->toBe('bonus-proofs/photo.jpg');
});

test('falls back to the most recently updated row and adds the unique index', function () {
    $first = EventBonus::factory()->create(['claimed_by_user_id' => null, 'updated_at' => now()->subHour()]);
    $latestId = insertBonusCopy($first, ['updated_at' => now()]);
    $other = EventBonus::factory()->create();

    $this->migration->up();

    expect(DB::table('event_bonuses')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$latestId, $other->id])->sort()->values()->all());

    expect(fn () => insertBonusCopy($other, []))->toThrow(UniqueConstraintViolationException::class);
});

test('keeps the largest quantity and points so per-occurrence claims are not lowered', function () {
    $older = EventBonus::factory()->create([
        'claimed_by_user_id' => null,
        'notes' => null,
        'quantity' => 3,
        'calculated_points' => 300,
        'updated_at' => now()->subHour(),
    ]);
    $newerId = insertBonusCopy($older, ['quantity' => 2, 'calculated_points' => 200, 'updated_at' => now()]);

    $this->migration->up();

    $row = DB::table('event_bonuses')->sole();
    expect($row->id)->toBe($newerId)
        ->and((int) $row->quantity)->toBe(3)
        ->and((int) $row->calculated_points)->toBe(300);
});
