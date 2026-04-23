<?php

use App\Livewire\Events\EventDashboard;
use App\Models\AuditLog;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use App\Scoring\Exceptions\RulesVersionLocked;
use App\Scoring\Exceptions\UnknownRuleSet;
use App\Services\RescoreService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses()->group('feature', 'scoring');

beforeEach(function () {
    Permission::create(['name' => 'view-events']);
    Permission::create(['name' => 'edit-events']);
    Permission::create(['name' => 'delete-events']);
    Permission::create(['name' => 'create-events']);

    $adminRole = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    $adminRole->givePermissionTo(['view-events', 'edit-events', 'delete-events', 'create-events']);

    $this->fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
});

function makeScoredEvent(EventType $fd, Mode $mode, int $pointsStored): array
{
    $event = Event::factory()->create([
        'event_type_id' => $fd->id,
        'rules_version' => '2025',
        'start_time' => now()->subDays(2),
        'end_time' => now()->subDay(),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'is_gota' => false,
    ]);
    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'mode_id' => $mode->id,
    ]);
    $contact = Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'operating_session_id' => $session->id,
        'mode_id' => $mode->id,
        'points' => $pointsStored,
        'is_duplicate' => false,
    ]);

    return [$event, $config, $contact];
}

test('rescore service recomputes points using newly selected rules version', function () {
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    // Register an override for an unshipped version the event will be pinned to.
    // Since only 2025 is registered, pinning to 2025 is fine; use the override path.
    ModeRulePoint::create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2025',
        'mode_id' => $mode->id,
        'points' => 7,
    ]);

    // Contact was logged with stale 2 points (before override existed).
    [$event, $config, $contact] = makeScoredEvent($this->fd, $mode, pointsStored: 2);

    $result = app(RescoreService::class)->rescoreEvent($event);

    expect($result['rescored'])->toBe(1)
        ->and($contact->fresh()->points)->toBe(7);
});

test('rescore service rejects an event pinned to an unregistered rules_version', function () {
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);
    [$event] = makeScoredEvent($this->fd, $mode, pointsStored: 2);

    Event::withoutRulesVersionLock(function () use ($event) {
        $event->rules_version = '2099';
        $event->save();
    });

    expect(fn () => app(RescoreService::class)->rescoreEvent($event->fresh()))
        ->toThrow(UnknownRuleSet::class);
});

test('rescore service zeros out duplicate contacts', function () {
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    [$event, $config, $contact] = makeScoredEvent($this->fd, $mode, pointsStored: 5);
    $contact->update(['is_duplicate' => true]);

    app(RescoreService::class)->rescoreEvent($event);

    expect($contact->fresh()->points)->toBe(0);
});

test('non-admin cannot open rescore modal', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-events']);
    $user->assignRole($role);

    $this->actingAs($user);

    $event = Event::factory()->create(['event_type_id' => $this->fd->id]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->call('openRescoreModal')
        ->assertStatus(403);
});

test('admin can rescore and audit log is written', function () {
    $admin = User::factory()->create();
    $admin->assignRole('System Administrator');
    $this->actingAs($admin);

    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);
    ModeRulePoint::create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2025',
        'mode_id' => $mode->id,
        'points' => 4,
    ]);

    [$event, $config, $contact] = makeScoredEvent($this->fd, $mode, pointsStored: 2);

    $component = Livewire::test(EventDashboard::class, ['event' => $event])
        ->call('openRescoreModal')
        ->set('rescoreTargetVersion', '2025')
        ->call('confirmRescore');

    expect($component->get('showRescoreModal'))->toBeFalse()
        ->and($contact->fresh()->points)->toBe(4);

    $log = AuditLog::where('action', 'event.rules_rescored')->first();
    expect($log)->not->toBeNull()
        ->and($log->new_values['rules_version'])->toBe('2025')
        ->and($log->new_values['contacts_rescored'])->toBe(1);
});

test('observer still blocks direct rules_version edits after event starts', function () {
    $event = Event::factory()->create([
        'rules_version' => '2025',
        'start_time' => now()->subDay(),
        'end_time' => now()->addDay(),
    ]);

    $event->rules_version = '2026';

    expect(fn () => $event->save())->toThrow(RulesVersionLocked::class);
});

test('rescore migrates event_bonuses to target version and recomputes points', function () {
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    [$event, $config, $contact] = makeScoredEvent($this->fd, $mode, pointsStored: 2);

    $bonus2025 = BonusType::factory()->create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2025',
        'code' => 'social_media',
        'base_points' => 100,
        'max_points' => null,
        'is_active' => true,
        'trigger_type' => 'manual',
    ]);
    $bonus2026 = BonusType::factory()->create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2026',
        'code' => 'social_media',
        'base_points' => 250,
        'max_points' => null,
        'is_active' => true,
        'trigger_type' => 'manual',
    ]);

    $eventBonus = EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonus2025->id,
        'quantity' => 1,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    Event::withoutRulesVersionLock(function () use ($event) {
        $event->rules_version = '2026';
        $event->save();
    });

    $result = app(RescoreService::class)->rescoreEvent($event->fresh());

    expect($result['bonuses_repointed'])->toBe(1)
        ->and($result['bonuses_recomputed'])->toBe(1)
        ->and($result['bonuses_invalidated'])->toBe(0);

    $eventBonus->refresh();
    expect($eventBonus->bonus_type_id)->toBe($bonus2026->id)
        ->and((int) $eventBonus->calculated_points)->toBe(250)
        ->and($eventBonus->is_verified)->toBeTrue();
});

test('rescore invalidates bonuses whose code is removed in target version', function () {
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    [$event, $config, $contact] = makeScoredEvent($this->fd, $mode, pointsStored: 2);

    $bonus2025 = BonusType::factory()->create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2025',
        'code' => 'retired_bonus',
        'base_points' => 100,
        'is_active' => true,
    ]);

    $eventBonus = EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonus2025->id,
        'quantity' => 1,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    Event::withoutRulesVersionLock(function () use ($event) {
        $event->rules_version = '2026';
        $event->save();
    });

    $result = app(RescoreService::class)->rescoreEvent($event->fresh());

    expect($result['bonuses_invalidated'])->toBe(1)
        ->and($result['bonuses_repointed'])->toBe(0);

    $eventBonus->refresh();
    expect($eventBonus->bonus_type_id)->toBe($bonus2025->id)
        ->and((int) $eventBonus->calculated_points)->toBe(0)
        ->and($eventBonus->is_verified)->toBeFalse();
});

test('rescore clamps recomputed bonus points to max_points', function () {
    $mode = Mode::factory()->create(['name' => 'CW', 'points_fd' => 2]);

    [$event, $config, $contact] = makeScoredEvent($this->fd, $mode, pointsStored: 2);

    $bonus2025 = BonusType::factory()->create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2025',
        'code' => 'social_media',
        'base_points' => 100,
        'max_points' => 100,
        'is_active' => true,
    ]);
    $bonus2026 = BonusType::factory()->create([
        'event_type_id' => $this->fd->id,
        'rules_version' => '2026',
        'code' => 'social_media',
        'base_points' => 100,
        'max_points' => 300,
        'is_active' => true,
    ]);

    $eventBonus = EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonus2025->id,
        'quantity' => 10,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    Event::withoutRulesVersionLock(function () use ($event) {
        $event->rules_version = '2026';
        $event->save();
    });

    app(RescoreService::class)->rescoreEvent($event->fresh());

    expect((int) $eventBonus->fresh()->calculated_points)->toBe(300);
});

test('withoutRulesVersionLock restores the lock after the callback', function () {
    $event = Event::factory()->create([
        'rules_version' => '2025',
        'start_time' => now()->subDay(),
        'end_time' => now()->addDay(),
    ]);

    Event::withoutRulesVersionLock(function () use ($event) {
        $event->rules_version = '2026';
        $event->save();
    });

    expect($event->fresh()->rules_version)->toBe('2026');

    // After the callback, the lock is re-engaged.
    $event->rules_version = '2025';
    expect(fn () => $event->save())->toThrow(RulesVersionLocked::class);
});
