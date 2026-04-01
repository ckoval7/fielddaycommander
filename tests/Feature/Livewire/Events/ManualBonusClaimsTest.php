<?php

use App\Livewire\Events\ManualBonusClaims;
use App\Models\BonusType;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\User;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\OperatingClassSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([PermissionSeeder::class, EventTypeSeeder::class, OperatingClassSeeder::class, BonusTypeSeeder::class]);

    $this->eventType = EventType::where('code', 'FD')->first();
    $classA = OperatingClass::where('code', 'A')->where('event_type_id', $this->eventType->id)->first();

    $this->event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'operating_class_id' => $classA->id,
    ]);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('verify-bonuses');
});

test('renders for users with verify-bonuses permission', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Manual Bonus Claims');
});

test('does not render for users without verify-bonuses permission', function () {
    $unprivileged = User::factory()->create();

    Livewire::actingAs($unprivileged)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertDontSee('Manual Bonus Claims');
});

test('shows social_media bonus for any operating class', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Social Media');
});

test('shows public_location bonus for class A', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Public Location');
});

test('hides public_location bonus for ineligible class', function () {
    $classB = OperatingClass::where('code', 'B')->where('event_type_id', $this->eventType->id)->first();
    $this->eventConfig->update(['operating_class_id' => $classB->id]);

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertDontSee('Public Location');
});

test('claiming a bonus creates an auto-verified EventBonus record', function () {
    $bonusType = BonusType::where('code', 'social_media')
        ->where('event_type_id', $this->eventType->id)
        ->first();

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->call('claim', $bonusType->id, 'https://facebook.com/our-fd-post');

    $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
        ->where('bonus_type_id', $bonusType->id)
        ->first();

    expect($bonus)->not->toBeNull()
        ->and($bonus->is_verified)->toBeTrue()
        ->and($bonus->calculated_points)->toBe(100)
        ->and($bonus->claimed_by_user_id)->toBe($this->user->id)
        ->and($bonus->verified_by_user_id)->toBe($this->user->id)
        ->and($bonus->verified_at)->not->toBeNull()
        ->and($bonus->notes)->toBe('https://facebook.com/our-fd-post');
});

test('claiming a bonus works without notes', function () {
    $bonusType = BonusType::where('code', 'public_location')
        ->where('event_type_id', $this->eventType->id)
        ->first();

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->call('claim', $bonusType->id);

    $bonus = EventBonus::where('event_configuration_id', $this->eventConfig->id)
        ->where('bonus_type_id', $bonusType->id)
        ->first();

    expect($bonus)->not->toBeNull()
        ->and($bonus->notes)->toBeNull();
});

test('cannot claim the same bonus twice', function () {
    $bonusType = BonusType::where('code', 'social_media')
        ->where('event_type_id', $this->eventType->id)
        ->first();

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->call('claim', $bonusType->id, 'first claim')
        ->call('claim', $bonusType->id, 'second claim');

    expect(EventBonus::where('event_configuration_id', $this->eventConfig->id)
        ->where('bonus_type_id', $bonusType->id)
        ->count())->toBe(1);
});

test('unclaiming deletes the EventBonus record', function () {
    $bonusType = BonusType::where('code', 'social_media')
        ->where('event_type_id', $this->eventType->id)
        ->first();

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->call('claim', $bonusType->id)
        ->call('unclaim', $bonusType->id);

    expect(EventBonus::where('event_configuration_id', $this->eventConfig->id)
        ->where('bonus_type_id', $bonusType->id)
        ->count())->toBe(0);
});

test('cannot claim a bonus type not in the manual list', function () {
    $nonManualBonus = BonusType::where('code', 'emergency_power')
        ->where('event_type_id', $this->eventType->id)
        ->first();

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->call('claim', $nonManualBonus->id);

    expect(EventBonus::where('event_configuration_id', $this->eventConfig->id)
        ->where('bonus_type_id', $nonManualBonus->id)
        ->count())->toBe(0);
});

test('does not render when event has no configuration', function () {
    $eventNoCfg = Event::factory()->create(['event_type_id' => $this->eventType->id]);

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $eventNoCfg])
        ->assertDontSee('Manual Bonus Claims');
});
