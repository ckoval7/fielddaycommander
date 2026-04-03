<?php

use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\GuestbookEntry;
use App\Services\GuestbookBonusSyncService;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->eventType = EventType::where('code', 'FD')->first();
    $this->event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->service = new GuestbookBonusSyncService;
});

describe('elected official visit bonus', function () {
    test('creates bonus when verified elected official entry exists', function () {
        GuestbookEntry::factory()->electedOfficial()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'elected_official_visit'))
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->is_verified)->toBeTrue();
    });

    test('removes bonus when no verified elected official entries remain', function () {
        $entry = GuestbookEntry::factory()->electedOfficial()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $this->service->sync($this->eventConfig);

        $entry->delete();
        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'elected_official_visit'))
            ->first();

        expect($bonus)->toBeNull();
    });

    test('does not create bonus for unverified elected official entry', function () {
        GuestbookEntry::factory()->electedOfficial()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_verified' => false,
        ]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'elected_official_visit'))
            ->first();

        expect($bonus)->toBeNull();
    });
});

describe('agency visit bonus', function () {
    test('creates bonus when verified agency entry exists', function () {
        GuestbookEntry::factory()->agency()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'agency_visit'))
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->is_verified)->toBeTrue();
    });

    test('removes bonus when no verified agency entries remain', function () {
        $entry = GuestbookEntry::factory()->agency()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $this->service->sync($this->eventConfig);

        $entry->delete();
        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'agency_visit'))
            ->first();

        expect($bonus)->toBeNull();
    });
});

describe('media publicity bonus', function () {
    test('creates bonus when verified media entry exists', function () {
        GuestbookEntry::factory()->media()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $this->service->sync($this->eventConfig);

        $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->whereHas('bonusType', fn ($q) => $q->where('code', 'media_publicity'))
            ->first();

        expect($bonus)->not->toBeNull()
            ->and($bonus->is_verified)->toBeTrue();
    });
});

describe('ARRL official entries', function () {
    test('do not create any bonus', function () {
        GuestbookEntry::factory()->arrlOfficial()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $this->service->sync($this->eventConfig);

        $bonusCount = EventBonus::where('event_configuration_id', $this->eventConfig->id)->count();

        expect($bonusCount)->toBe(0);
    });
});

describe('full sync', function () {
    test('handles all three bonus types in one call', function () {
        GuestbookEntry::factory()->electedOfficial()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        GuestbookEntry::factory()->agency()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        GuestbookEntry::factory()->media()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $this->service->sync($this->eventConfig);

        $bonusCount = EventBonus::where('event_configuration_id', $this->eventConfig->id)->count();

        expect($bonusCount)->toBe(3);

        $codes = EventBonus::where('event_configuration_id', $this->eventConfig->id)
            ->with('bonusType')
            ->get()
            ->pluck('bonusType.code')
            ->sort()
            ->values()
            ->toArray();

        expect($codes)->toBe(['agency_visit', 'elected_official_visit', 'media_publicity']);
    });
});
