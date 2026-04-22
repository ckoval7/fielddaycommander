<?php

use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\Message;
use App\Services\RescoreService;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
});

it('recomputes derived bonuses during rescore', function () {
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();

    Message::factory()->create([
        'event_configuration_id' => $config->id,
        'is_sm_message' => true,
        'sent_at' => now(),
    ]);

    app(RescoreService::class)->rescoreEvent($event->fresh());

    $row = EventBonus::where('event_configuration_id', $config->id)
        ->whereHas('bonusType', fn ($q) => $q->where('code', 'sm_sec_message'))
        ->first();
    expect($row)->not->toBeNull();
});
