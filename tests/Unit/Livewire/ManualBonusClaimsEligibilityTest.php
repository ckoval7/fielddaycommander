<?php

use App\Livewire\Events\ManualBonusClaims;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\OperatingClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, OperatingClassSeeder::class, BonusTypeSeeder::class]);
});

it('eligible bonus types include every manual code for this ruleset version and exclude derived codes', function () {
    $eventType = EventType::where('code', 'FD')->first();
    $classA = OperatingClass::where('code', 'A')->where('event_type_id', $eventType->id)->first();

    $event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'rules_version' => '2025',
    ]);

    EventConfiguration::factory()->for($event)->create([
        'operating_class_id' => $classA->id,
    ]);

    $component = Livewire::test(ManualBonusClaims::class, ['event' => $event->fresh()]);

    $codes = $component->instance()->eligibleBonusTypes->pluck('code')->sort()->values()->all();

    expect($codes)->toContain('social_media', 'public_location', 'public_info_booth', 'educational_activity', 'web_submission', 'media_publicity')
        ->and($codes)->not->toContain('sm_sec_message', 'nts_message', 'w1aw_bulletin', 'elected_official_visit', 'agency_visit', 'youth_participation');
});
