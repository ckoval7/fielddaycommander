<?php

use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\Message;
use App\Scoring\Bonuses\FieldDay2025\SmSecMessageStrategy;
use App\Scoring\DomainEvents\MessageChanged;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->event = Event::factory()->create(['rules_version' => '2025']);
    $this->config = EventConfiguration::factory()->for($this->event)->create();
    $this->bt = BonusType::where('event_type_id', $this->event->event_type_id)
        ->where('rules_version', '2025')
        ->where('code', 'sm_sec_message')
        ->first();
});

it('reports code and trigger type', function () {
    $s = new SmSecMessageStrategy;
    expect($s->code())->toBe('sm_sec_message')
        ->and($s->triggerType())->toBe('derived')
        ->and($s->subscribesTo())->toBe([MessageChanged::class]);
});

it('writes the row when a sent SM message exists', function () {
    Message::factory()->create([
        'event_configuration_id' => $this->config->id,
        'is_sm_message' => true,
        'sent_at' => now(),
    ]);

    (new SmSecMessageStrategy)->reconcile($this->config);

    $row = EventBonus::where('event_configuration_id', $this->config->id)
        ->where('bonus_type_id', $this->bt->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(1)
        ->and($row->calculated_points)->toBe((int) $this->bt->base_points);
});

it('writes the row when an SM message was received_delivered', function () {
    Message::factory()->create([
        'event_configuration_id' => $this->config->id,
        'is_sm_message' => true,
        'role' => 'received_delivered',
        'sent_at' => null,
    ]);

    (new SmSecMessageStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(1);
});

it('deletes the row when no qualifying SM message exists', function () {
    EventBonus::factory()->create([
        'event_configuration_id' => $this->config->id,
        'bonus_type_id' => $this->bt->id,
        'quantity' => 1,
        'calculated_points' => $this->bt->base_points,
    ]);

    (new SmSecMessageStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(0);
});

it('is idempotent', function () {
    Message::factory()->create([
        'event_configuration_id' => $this->config->id,
        'is_sm_message' => true,
        'sent_at' => now(),
    ]);

    $strategy = new SmSecMessageStrategy;
    $strategy->reconcile($this->config);
    $strategy->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(1);
});
