<?php

use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\Message;
use App\Scoring\Bonuses\FieldDay2025\NtsMessageStrategy;
use App\Scoring\DomainEvents\MessageChanged;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->event = Event::factory()->create(['rules_version' => '2025']);
    $this->config = EventConfiguration::factory()->for($this->event)->create();
    $this->bt = BonusType::where('event_type_id', $this->event->event_type_id)
        ->where('rules_version', '2025')
        ->where('code', 'nts_message')
        ->first();
});

it('reports code, trigger type, and subscriptions', function () {
    $s = new NtsMessageStrategy;
    expect($s->code())->toBe('nts_message')
        ->and($s->triggerType())->toBe('derived')
        ->and($s->subscribesTo())->toBe([MessageChanged::class]);
});

it('writes no row when there are no qualifying messages', function () {
    (new NtsMessageStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(0);
});

it('writes quantity and points for 3 sent non-SM messages', function () {
    Message::factory()->count(3)->create([
        'event_configuration_id' => $this->config->id,
        'is_sm_message' => false,
        'sent_at' => now(),
    ]);

    (new NtsMessageStrategy)->reconcile($this->config);

    $row = EventBonus::where('event_configuration_id', $this->config->id)
        ->where('bonus_type_id', $this->bt->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(3)
        ->and($row->calculated_points)->toBe(3 * (int) $this->bt->base_points);
});

it('clamps quantity to max_occurrences for 15 non-SM messages', function () {
    Message::factory()->count(15)->create([
        'event_configuration_id' => $this->config->id,
        'is_sm_message' => false,
        'sent_at' => now(),
    ]);

    (new NtsMessageStrategy)->reconcile($this->config);

    $row = EventBonus::where('event_configuration_id', $this->config->id)
        ->where('bonus_type_id', $this->bt->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe((int) $this->bt->max_occurrences)
        ->and($row->calculated_points)->toBe((int) $this->bt->max_occurrences * (int) $this->bt->base_points);
});

it('is idempotent', function () {
    Message::factory()->count(3)->create([
        'event_configuration_id' => $this->config->id,
        'is_sm_message' => false,
        'sent_at' => now(),
    ]);

    $strategy = new NtsMessageStrategy;
    $strategy->reconcile($this->config);
    $strategy->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(1);
});
