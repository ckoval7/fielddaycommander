<?php

use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use App\Models\W1awBulletin;
use App\Scoring\Bonuses\WinterFieldDay2026\SpecialBulletinStrategy;
use App\Scoring\Bonuses\WinterFieldDay2027\BulletinStrategy;
use App\Scoring\DomainEvents\W1awBulletinChanged;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->wfd = EventType::where('code', 'WFD')->firstOrFail();
});

/**
 * @return array{0: EventConfiguration, 1: BonusType}
 */
function wfdBulletinSetup(EventType $wfd, string $version, string $code): array
{
    $event = Event::factory()->create(['event_type_id' => $wfd->id, 'rules_version' => $version]);
    $config = EventConfiguration::factory()->for($event)->create();
    $bonusType = BonusType::where('event_type_id', $wfd->id)
        ->where('rules_version', $version)
        ->where('code', $code)
        ->firstOrFail();

    return [$config, $bonusType];
}

dataset('wfd bulletin objectives', [
    '2026 special bulletin' => ['2026', 'special_bulletin', SpecialBulletinStrategy::class],
    '2027 bulletin' => ['2027', 'bulletin', BulletinStrategy::class],
]);

it('is derived from the saved bulletin', function (string $version, string $code, string $class) {
    $strategy = app($class);

    expect($strategy->code())->toBe($code)
        ->and($strategy->triggerType())->toBe('derived')
        ->and($strategy->subscribesTo())->toBe([W1awBulletinChanged::class]);
})->with('wfd bulletin objectives');

it('achieves the objective when the bulletin is saved and drops it when deleted', function (string $version, string $code) {
    [$config, $bonusType] = wfdBulletinSetup($this->wfd, $version, $code);

    $bulletin = W1awBulletin::factory()->create(['event_configuration_id' => $config->id]);

    $row = EventBonus::where('event_configuration_id', $config->id)->where('bonus_type_id', $bonusType->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(1)
        ->and($row->is_verified)->toBeTrue()
        ->and($config->fresh()->scoreBreakdown()->value('objective_multiplier'))->toBe(1);

    $bulletin->delete();

    expect(EventBonus::where('event_configuration_id', $config->id)->where('bonus_type_id', $bonusType->id)->exists())
        ->toBeFalse();
})->with('wfd bulletin objectives');

it('keeps a claim made by hand when no bulletin was saved', function (string $version, string $code, string $class) {
    [$config, $bonusType] = wfdBulletinSetup($this->wfd, $version, $code);

    EventBonus::create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonusType->id,
        'claimed_by_user_id' => User::factory()->create()->id,
        'quantity' => 1,
        'calculated_points' => 0,
        'is_verified' => true,
    ]);

    app($class)->reconcile($config);

    expect(EventBonus::where('event_configuration_id', $config->id)->where('bonus_type_id', $bonusType->id)->exists())
        ->toBeTrue();
})->with('wfd bulletin objectives');

it('is seeded as derived so it leaves the manual claims list', function (string $version, string $code) {
    [, $bonusType] = wfdBulletinSetup($this->wfd, $version, $code);

    expect($bonusType->trigger_type)->toBe('derived');
})->with('wfd bulletin objectives');
