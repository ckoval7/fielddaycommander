<?php

use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Scoring\Bonuses\FieldDay2025\YouthParticipationStrategy;
use App\Scoring\DomainEvents\QsoLogged;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
    $this->event = Event::factory()->create(['rules_version' => '2025']);
    $this->config = EventConfiguration::factory()->for($this->event)->create();
    $this->bt = BonusType::where('event_type_id', $this->event->event_type_id)
        ->where('rules_version', '2025')
        ->where('code', 'youth_participation')
        ->first();
});

it('reports hybrid trigger and subscribes to QsoLogged', function () {
    $s = new YouthParticipationStrategy;
    expect($s->code())->toBe('youth_participation')
        ->and($s->triggerType())->toBe('hybrid')
        ->and($s->subscribesTo())->toBe([QsoLogged::class]);
});

it('computes total = auto + manual_quantity_adjustment capped at max_occurrences', function () {
    EventBonus::create([
        'event_configuration_id' => $this->config->id,
        'bonus_type_id' => $this->bt->id,
        'quantity' => 3,
        'calculated_points' => 3 * (int) $this->bt->base_points,
        'manual_quantity_adjustment' => 3,
        'is_verified' => true,
        'verified_at' => now(),
    ]);

    (new YouthParticipationStrategy)->reconcile($this->config);

    $row = EventBonus::where('event_configuration_id', $this->config->id)->first();
    expect($row->quantity)->toBe(3)
        ->and($row->manual_quantity_adjustment)->toBe(3)
        ->and($row->calculated_points)->toBe(3 * (int) $this->bt->base_points);
});

it('deletes the row when auto is zero and adjustment is null', function () {
    EventBonus::factory()->create([
        'event_configuration_id' => $this->config->id,
        'bonus_type_id' => $this->bt->id,
        'quantity' => 0,
        'calculated_points' => 0,
        'manual_quantity_adjustment' => null,
    ]);

    (new YouthParticipationStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(0);
});
