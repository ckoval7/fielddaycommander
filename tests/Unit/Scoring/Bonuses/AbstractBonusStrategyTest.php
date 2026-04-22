<?php

use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Scoring\Bonuses\AbstractBonusStrategy;

class FakeStrategy extends AbstractBonusStrategy
{
    public function code(): string
    {
        return 'test_bonus';
    }

    public function triggerType(): string
    {
        return 'derived';
    }

    public function subscribesTo(): array
    {
        return [];
    }

    public function reconcile(EventConfiguration $config): void {}

    public function callWriteOrDelete(EventConfiguration $c, ?BonusType $bt, ?int $quantity, ?int $points): void
    {
        $this->writeOrDelete($c, $bt, $quantity, $points);
    }
}

it('deletes the row when quantity is null', function () {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();
    $bt = BonusType::factory()->create([
        'event_type_id' => $event->event_type_id,
        'rules_version' => '2025',
        'code' => 'test_bonus',
    ]);
    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bt->id,
        'quantity' => 1,
        'calculated_points' => 50,
    ]);

    (new FakeStrategy)->callWriteOrDelete($config, $bt, null, null);

    expect(EventBonus::where('event_configuration_id', $config->id)->count())->toBe(0);
});

it('upserts the row with the supplied quantity and points', function () {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();
    $bt = BonusType::factory()->create([
        'event_type_id' => $event->event_type_id,
        'rules_version' => '2025',
        'code' => 'test_bonus',
        'base_points' => 25,
    ]);

    (new FakeStrategy)->callWriteOrDelete($config, $bt, 3, 75);

    $row = EventBonus::where('event_configuration_id', $config->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(3)
        ->and($row->calculated_points)->toBe(75)
        ->and($row->is_verified)->toBeTrue();
});

it('is a no-op when BonusType is null', function () {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();

    (new FakeStrategy)->callWriteOrDelete($config, null, 5, 100);

    expect(EventBonus::where('event_configuration_id', $config->id)->count())->toBe(0);
});
