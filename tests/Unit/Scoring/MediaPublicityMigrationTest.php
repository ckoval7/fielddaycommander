<?php

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
});

uses()->group('unit', 'scoring', 'migration');

test('convert_media_publicity_to_manual deletes pre-existing auto-created event_bonuses rows', function () {
    // Roll back just this migration so we can re-seed state as if we were running it
    // against a DB where the old derived behavior had already created rows.
    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/2026_04_23_194738_convert_media_publicity_to_manual.php',
        '--force' => true,
    ]);

    $fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);

    // Simulate post-backfill, pre-flip state: derived + existing auto rows.
    DB::table('bonus_types')
        ->where('code', 'media_publicity')
        ->update(['trigger_type' => 'derived']);

    $bonusType = BonusType::where('code', 'media_publicity')
        ->where('rules_version', '2025')
        ->where('event_type_id', $fd->id)
        ->firstOrFail();

    $config = EventConfiguration::factory()->create();

    $eventBonus = EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonusType->id,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    // Re-run the migration — it should delete the orphan row.
    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_04_23_194738_convert_media_publicity_to_manual.php',
        '--force' => true,
    ]);

    expect(EventBonus::find($eventBonus->id))->toBeNull();
    expect(BonusType::find($bonusType->id)->trigger_type)->toBe('manual');
});
