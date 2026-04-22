<?php

use App\Livewire\Events\ManualBonusClaims;
use App\Models\AuditLog;
use App\Models\BonusType;
use App\Models\Contact;
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
    $classD = OperatingClass::where('code', 'D')->where('event_type_id', $this->eventType->id)->first();
    $this->eventConfig->update(['operating_class_id' => $classD->id]);

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

test('cannot unclaim a bonus type not in the manual list', function () {
    $nonManualBonus = BonusType::where('code', 'emergency_power')
        ->where('event_type_id', $this->eventType->id)
        ->first();

    // Create a bonus record directly (simulating an auto-synced bonus)
    EventBonus::create([
        'event_configuration_id' => $this->eventConfig->id,
        'bonus_type_id' => $nonManualBonus->id,
        'claimed_by_user_id' => $this->user->id,
        'quantity' => 1,
        'calculated_points' => $nonManualBonus->base_points,
        'is_verified' => true,
        'verified_by_user_id' => $this->user->id,
        'verified_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->call('unclaim', $nonManualBonus->id);

    expect(EventBonus::where('event_configuration_id', $this->eventConfig->id)
        ->where('bonus_type_id', $nonManualBonus->id)
        ->count())->toBe(1);
});

test('does not render when event has no configuration', function () {
    $eventNoCfg = Event::factory()->create(['event_type_id' => $this->eventType->id]);

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $eventNoCfg])
        ->assertDontSee('Manual Bonus Claims');
});

test('shows educational_activity bonus for class A', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Educational Activity');
});

test('shows web_submission bonus for any class', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Web Submission');
});

test('shows youth participation section for class A', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Youth Participation');
});

test('saveAdditionalYouth dispatches bonus-claimed event', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->set('additionalYouth', 3)
        ->call('saveAdditionalYouth')
        ->assertDispatched('bonus-claimed');
});

test('youth section shows auto-detected youth count', function () {
    $youthUser = User::factory()->create(['is_youth' => true]);
    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'logger_user_id' => $youthUser->id,
        'is_duplicate' => false,
    ]);

    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Registered youth with QSOs')
        ->assertSee('20 pts');
});

test('shows elected_official_visit and agency_visit as manual claims', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Elected Official Visit')
        ->assertSee('Served Agency Visit');
});

test('shows public_info_booth bonus for class A', function () {
    Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->assertSee('Information Booth');
});

describe('audit logging', function () {
    test('claiming a bonus logs to audit log', function () {
        $bonusType = BonusType::where('code', 'social_media')
            ->where('event_type_id', $this->eventType->id)
            ->first();

        Livewire::actingAs($this->user)
            ->test(ManualBonusClaims::class, ['event' => $this->event])
            ->call('claim', $bonusType->id, 'Posted on Twitter');

        $bonus = EventBonus::where('bonus_type_id', $bonusType->id)->first();

        $auditLog = AuditLog::where('action', 'bonus.claimed')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->user->id);
        expect($auditLog->auditable_type)->toBe(EventBonus::class);
        expect($auditLog->auditable_id)->toBe($bonus->id);
        expect($auditLog->new_values['bonus_type'])->toBe('social_media');
        expect($auditLog->new_values['points'])->toBe($bonusType->base_points);
    });

    test('unclaiming a bonus logs to audit log', function () {
        $bonusType = BonusType::where('code', 'social_media')
            ->where('event_type_id', $this->eventType->id)
            ->first();

        $bonus = EventBonus::create([
            'event_configuration_id' => $this->eventConfig->id,
            'bonus_type_id' => $bonusType->id,
            'claimed_by_user_id' => $this->user->id,
            'quantity' => 1,
            'calculated_points' => $bonusType->base_points,
            'is_verified' => true,
            'verified_by_user_id' => $this->user->id,
            'verified_at' => now(),
        ]);

        Livewire::actingAs($this->user)
            ->test(ManualBonusClaims::class, ['event' => $this->event])
            ->call('unclaim', $bonusType->id);

        $auditLog = AuditLog::where('action', 'bonus.unclaimed')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values['bonus_type'])->toBe('social_media');
        expect($auditLog->old_values['points'])->toBe($bonusType->base_points);
    });
});

test('eligible bonus list is scoped to the event rules_version', function () {
    // Seed an extra TEST-version row for an already-seeded manual-claim code.
    // Without rules_version scoping the component would render both versions.
    BonusType::factory()->create([
        'event_type_id' => $this->eventType->id,
        'code' => 'social_media',
        'rules_version' => 'TEST',
        'base_points' => 999,
        'is_active' => true,
    ]);

    $eligible = Livewire::actingAs($this->user)
        ->test(ManualBonusClaims::class, ['event' => $this->event])
        ->get('eligibleBonusTypes');

    $socialMedia = $eligible->where('code', 'social_media');

    expect($socialMedia)->toHaveCount(1)
        ->and($socialMedia->first()->rules_version)->toBe($this->event->resolved_rules_version);
});
