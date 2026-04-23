<?php

use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\W1awBulletin;
use App\Scoring\Bonuses\FieldDay2025\W1awBulletinStrategy;
use App\Scoring\DomainEvents\W1awBulletinChanged;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->event = Event::factory()->create(['rules_version' => '2025']);
    $this->config = EventConfiguration::factory()->for($this->event)->create();
    $this->bt = BonusType::where('event_type_id', $this->event->event_type_id)
        ->where('rules_version', '2025')
        ->where('code', 'w1aw_bulletin')
        ->first();
});

it('reports code and trigger type', function () {
    $s = new W1awBulletinStrategy;
    expect($s->code())->toBe('w1aw_bulletin')
        ->and($s->triggerType())->toBe('derived')
        ->and($s->subscribesTo())->toBe([W1awBulletinChanged::class]);
});

it('writes the row when a W1AW bulletin exists', function () {
    W1awBulletin::factory()->create(['event_configuration_id' => $this->config->id]);

    (new W1awBulletinStrategy)->reconcile($this->config);

    $row = EventBonus::where('event_configuration_id', $this->config->id)
        ->where('bonus_type_id', $this->bt->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(1)
        ->and($row->calculated_points)->toBe((int) $this->bt->base_points);
});

it('deletes the row when no bulletin exists', function () {
    EventBonus::factory()->create([
        'event_configuration_id' => $this->config->id,
        'bonus_type_id' => $this->bt->id,
        'quantity' => 1,
        'calculated_points' => $this->bt->base_points,
    ]);

    (new W1awBulletinStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(0);
});

it('is idempotent', function () {
    W1awBulletin::factory()->create(['event_configuration_id' => $this->config->id]);

    $strategy = new W1awBulletinStrategy;
    $strategy->reconcile($this->config);
    $strategy->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(1);
});
