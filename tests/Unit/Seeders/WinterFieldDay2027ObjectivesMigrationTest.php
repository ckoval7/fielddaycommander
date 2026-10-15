<?php

use App\Models\BonusType;
use App\Models\EventType;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses()->group('unit', 'scoring');

/**
 * Production updates run migrations but not seeders, so the migration must
 * produce exactly the 2027 rows a fresh install gets from BonusTypeSeeder.
 */
function runWfd2027ObjectivesMigration(): void
{
    $migration = require database_path('migrations/2026_10_15_164535_seed_winter_field_day_2027_objectives.php');
    $migration->up();

    // Production applies this one right after, moving the bulletin to derived.
    $derive = require database_path('migrations/2026_10_15_181619_derive_winter_field_day_bulletin_objectives.php');
    $derive->up();
}

/**
 * @return array<string, array<string, mixed>>
 */
function wfd2027ObjectiveSnapshot(int $eventTypeId): array
{
    return BonusType::query()
        ->where('event_type_id', $eventTypeId)
        ->where('rules_version', '2027')
        ->orderBy('code')
        ->get()
        ->mapWithKeys(fn (BonusType $type) => [$type->code => [
            'name' => $type->name,
            'description' => $type->description,
            'trigger_type' => $type->trigger_type,
            'objective_multiplier' => (int) $type->objective_multiplier,
            'base_points' => (int) $type->base_points,
            'requires_proof' => (bool) $type->requires_proof,
            'is_active' => (bool) $type->is_active,
        ]])
        ->all();
}

beforeEach(function () {
    $this->seed(EventTypeSeeder::class);
    $this->wfd = EventType::where('code', 'WFD')->firstOrFail();
});

test('the migration inserts the same 2027 objectives as the seeder', function () {
    runWfd2027ObjectivesMigration();
    $fromMigration = wfd2027ObjectiveSnapshot($this->wfd->id);

    BonusType::query()->where('rules_version', '2027')->delete();
    $this->seed(BonusTypeSeeder::class);
    $fromSeeder = wfd2027ObjectiveSnapshot($this->wfd->id);

    expect($fromMigration)->toHaveCount(13)
        ->and($fromMigration)->toBe($fromSeeder);
});

test('the migration is idempotent and leaves existing rows alone', function () {
    runWfd2027ObjectivesMigration();

    BonusType::query()
        ->where('rules_version', '2027')
        ->where('code', 'bulletin')
        ->update(['name' => 'Edited by hand']);

    runWfd2027ObjectivesMigration();

    expect(BonusType::query()->where('rules_version', '2027')->count())->toBe(13)
        ->and(BonusType::query()->where('rules_version', '2027')->where('code', 'bulletin')->value('name'))
        ->toBe('Edited by hand');
});

test('the migration never touches 2026 rows', function () {
    $this->seed(BonusTypeSeeder::class);
    BonusType::query()->where('rules_version', '2027')->delete();

    $before = BonusType::query()->where('rules_version', '2026')->orderBy('id')->get()->toArray();

    runWfd2027ObjectivesMigration();

    expect(BonusType::query()->where('rules_version', '2026')->orderBy('id')->get()->toArray())->toBe($before);
});

test('the bulletin migration makes both WFD bulletin objectives derived and reverses cleanly', function () {
    $this->seed(BonusTypeSeeder::class);
    BonusType::query()->whereIn('code', ['special_bulletin', 'bulletin'])->update(['trigger_type' => 'manual']);

    $migration = require database_path('migrations/2026_10_15_181619_derive_winter_field_day_bulletin_objectives.php');
    $triggerTypes = fn () => BonusType::query()
        ->where('event_type_id', $this->wfd->id)
        ->whereIn('code', ['special_bulletin', 'bulletin'])
        ->orderBy('code')
        ->pluck('trigger_type', 'code')
        ->all();

    $migration->up();
    expect($triggerTypes())->toBe(['bulletin' => 'derived', 'special_bulletin' => 'derived']);

    $migration->down();
    expect($triggerTypes())->toBe(['bulletin' => 'manual', 'special_bulletin' => 'manual']);
});
