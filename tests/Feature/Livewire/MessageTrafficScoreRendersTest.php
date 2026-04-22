<?php

use App\Livewire\Dashboard\Widgets\MessageTrafficScore;
use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
});

it('renders with persisted event_bonuses rows', function () {
    $event = Event::factory()->create([
        'rules_version' => '2025',
        'start_time' => now()->subHour(),
        'end_time' => now()->addHour(),
    ]);
    $config = EventConfiguration::factory()->for($event)->create();

    $bt = BonusType::where('event_type_id', $event->event_type_id)
        ->where('rules_version', '2025')
        ->where('code', 'sm_sec_message')
        ->first();

    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bt->id,
        'quantity' => 1,
        'calculated_points' => 100,
    ]);

    Livewire::test(MessageTrafficScore::class)
        ->assertOk();
});
