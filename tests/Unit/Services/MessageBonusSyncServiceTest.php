<?php

use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Message;
use App\Models\W1awBulletin;
use App\Services\MessageBonusSyncService;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->eventType = EventType::where('code', 'FD')->first();
    $this->event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->service = new MessageBonusSyncService;
});

describe('SM/SEC message bonus', function () {
    test('creates bonus when SM message exists', function () {
        Message::factory()->smMessage()->create(['event_configuration_id' => $this->eventConfig->id]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'sm_sec_message'))
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->calculated_points)->toBe(100)
            ->and($bonus->is_verified)->toBeTrue();
    });

    test('removes bonus when SM message is deleted', function () {
        $message = Message::factory()->smMessage()->create(['event_configuration_id' => $this->eventConfig->id]);
        $this->service->sync($this->eventConfig);

        $message->delete();
        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'sm_sec_message'))
            ->first();

        expect($bonus)->toBeNull();
    });
});

describe('message handling bonus', function () {
    test('calculates points from message count', function () {
        Message::factory()->count(5)->create(['event_configuration_id' => $this->eventConfig->id]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'nts_message'))
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->calculated_points)->toBe(50)
            ->and($bonus->quantity)->toBe(5);
    });

    test('caps at 100 points for 10+ messages', function () {
        Message::factory()->count(15)->create(['event_configuration_id' => $this->eventConfig->id]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'nts_message'))
            ->first();

        expect($bonus->calculated_points)->toBe(100)
            ->and($bonus->quantity)->toBe(15);
    });

    test('excludes SM message from traffic count', function () {
        Message::factory()->smMessage()->create(['event_configuration_id' => $this->eventConfig->id]);
        Message::factory()->count(3)->create(['event_configuration_id' => $this->eventConfig->id]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'nts_message'))
            ->first();

        expect($bonus->quantity)->toBe(3)
            ->and($bonus->calculated_points)->toBe(30);
    });

    test('removes bonus when all messages deleted', function () {
        $message = Message::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        $this->service->sync($this->eventConfig);

        $message->delete();
        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'nts_message'))
            ->first();

        expect($bonus)->toBeNull();
    });
});

describe('W1AW bulletin bonus', function () {
    test('creates bonus when bulletin exists', function () {
        W1awBulletin::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'w1aw_bulletin'))
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->calculated_points)->toBe(100)
            ->and($bonus->is_verified)->toBeTrue();
    });

    test('removes bonus when bulletin is deleted', function () {
        $bulletin = W1awBulletin::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        $this->service->sync($this->eventConfig);

        $bulletin->delete();
        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'w1aw_bulletin'))
            ->first();

        expect($bonus)->toBeNull();
    });
});

describe('observer integration', function () {
    test('creating a message auto-syncs bonus via observer', function () {
        Message::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'nts_message'))
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->calculated_points)->toBe(10);
    })->skip('Requires Task 6: Observers');

    test('deleting a message auto-syncs bonus via observer', function () {
        $message = Message::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
        $message->delete();

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'nts_message'))
            ->first();

        expect($bonus)->toBeNull();
    })->skip('Requires Task 6: Observers');

    test('creating a W1AW bulletin auto-syncs bonus via observer', function () {
        W1awBulletin::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'w1aw_bulletin'))
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->calculated_points)->toBe(100);
    })->skip('Requires Task 6: Observers');
});
