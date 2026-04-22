<?php

use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Scoring\Bonuses\FieldDay2025\MediaPublicityStrategy;
use App\Scoring\DomainEvents\GuestbookEntryChanged;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->event = Event::factory()->create(['rules_version' => '2025']);
    $this->config = EventConfiguration::factory()->for($this->event)->create();
    $this->bt = BonusType::where('event_type_id', $this->event->event_type_id)
        ->where('rules_version', '2025')
        ->where('code', 'media_publicity')
        ->first();
});

it('reports code and trigger type', function () {
    $s = new MediaPublicityStrategy;
    expect($s->code())->toBe('media_publicity')
        ->and($s->triggerType())->toBe('derived')
        ->and($s->subscribesTo())->toBe([GuestbookEntryChanged::class]);
});

it('writes the row when a verified media entry exists', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    (new MediaPublicityStrategy)->reconcile($this->config);

    $row = EventBonus::where('event_configuration_id', $this->config->id)
        ->where('bonus_type_id', $this->bt->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->quantity)->toBe(1)
        ->and($row->calculated_points)->toBe((int) $this->bt->base_points);
});

it('deletes the row when no qualifying entry exists', function () {
    EventBonus::factory()->create([
        'event_configuration_id' => $this->config->id,
        'bonus_type_id' => $this->bt->id,
        'quantity' => 1,
        'calculated_points' => $this->bt->base_points,
    ]);

    (new MediaPublicityStrategy)->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(0);
});

it('is idempotent', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    $strategy = new MediaPublicityStrategy;
    $strategy->reconcile($this->config);
    $strategy->reconcile($this->config);

    expect(EventBonus::where('event_configuration_id', $this->config->id)->count())->toBe(1);
});
