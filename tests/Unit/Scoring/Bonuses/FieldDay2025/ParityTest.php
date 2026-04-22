<?php

use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\Message;
use App\Models\W1awBulletin;
use App\Scoring\EventBonusReconciler;
use App\Services\GuestbookBonusSyncService;
use App\Services\MessageBonusSyncService;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

function buildFixture(): EventConfiguration
{
    $event = Event::factory()->create(['rules_version' => '2025']);
    $config = EventConfiguration::factory()->for($event)->create();

    Message::factory()->create([
        'event_configuration_id' => $config->id,
        'is_sm_message' => true,
        'sent_at' => now(),
    ]);
    Message::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'is_sm_message' => false,
        'sent_at' => now(),
    ]);
    W1awBulletin::factory()->create(['event_configuration_id' => $config->id]);
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $config->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    return $config;
}

function eventBonusesByCode(EventConfiguration $config, array $codes): array
{
    return EventBonus::where('event_configuration_id', $config->id)
        ->with('bonusType')
        ->get()
        ->filter(fn ($b) => in_array($b->bonusType?->code, $codes, true))
        ->map(fn ($b) => [
            'code' => $b->bonusType->code,
            'quantity' => $b->quantity,
            'calculated_points' => $b->calculated_points,
        ])
        ->sortBy('code')
        ->values()
        ->toArray();
}

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);
});

it('sync-service path and strategy path produce identical rows for parity codes', function () {
    $parityCodes = [
        'sm_sec_message',
        'nts_message',
        'w1aw_bulletin',
        'elected_official_visit',
        'agency_visit',
        'media_publicity',
    ];

    // Sync-service path
    config(['scoring.use_bonus_strategies' => false]);
    $configA = buildFixture();
    app(MessageBonusSyncService::class)->sync($configA);
    app(GuestbookBonusSyncService::class)->sync($configA);
    $syncRows = eventBonusesByCode($configA, $parityCodes);

    // Strategy path
    config(['scoring.use_bonus_strategies' => true]);
    $configB = buildFixture();
    app(EventBonusReconciler::class)->reconcileAll($configB);
    $strategyRows = eventBonusesByCode($configB, $parityCodes);

    expect($strategyRows)->toEqual($syncRows);
});
