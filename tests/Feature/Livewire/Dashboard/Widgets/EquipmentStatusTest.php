<?php

use App\Livewire\Dashboard\Widgets\EquipmentStatus;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);
});

// ============================================================================
// EQUIPMENT STATUS WIDGET TESTS
// ============================================================================

describe('EquipmentStatus Widget', function () {
    test('component renders successfully without permission', function () {
        Livewire::test(EquipmentStatus::class)
            ->assertOk();
    });

    test('component renders successfully with permission', function () {
        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        Livewire::test(EquipmentStatus::class)
            ->assertOk();
    });

    test('component mounts with tvMode parameter', function () {
        Livewire::test(EquipmentStatus::class, ['tvMode' => true])
            ->assertSet('tvMode', true);

        Livewire::test(EquipmentStatus::class, ['tvMode' => false])
            ->assertSet('tvMode', false);
    });

    test('hasPermission is false without view-equipment permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->hasPermission)->toBeFalse();
    });

    test('hasPermission is true with view-equipment permission', function () {
        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->hasPermission)->toBeTrue();
    });

    test('event is null without permission even when active event exists', function () {
        Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->event)->toBeNull();
    });

    test('event is loaded with permission when active event exists', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->event)->not->toBeNull();
        expect($component->event->id)->toBe($event->id);
    });

    test('stations returns empty collection without permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->stations)->toBeEmpty();
    });

    test('stations returns empty collection when event has no configuration', function () {
        Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->stations)->toBeEmpty();
    });

    test('stations returns stations for active event with permission', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        Station::factory()->count(3)->create([
            'event_configuration_id' => $config->id,
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->stations)->toHaveCount(3);
    });

    test('stationCount returns zero without permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->stationCount)->toBe(0);
    });

    test('stationCount returns correct count with permission', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        Station::factory()->count(4)->create([
            'event_configuration_id' => $config->id,
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->stationCount)->toBe(4);
    });

    test('activeStations returns zero without permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->activeStations)->toBe(0);
    });

    test('activeStations counts stations with active operating sessions', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $activeStation = Station::factory()->create([
            'event_configuration_id' => $config->id,
        ]);

        $idleStation = Station::factory()->create([
            'event_configuration_id' => $config->id,
        ]);

        // Active operating session (started, no end time)
        OperatingSession::factory()->create([
            'station_id' => $activeStation->id,
            'start_time' => appNow()->subHours(1),
            'end_time' => null,
        ]);

        // Ended operating session (should not count)
        OperatingSession::factory()->create([
            'station_id' => $idleStation->id,
            'start_time' => appNow()->subHours(3),
            'end_time' => appNow()->subHours(1),
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->activeStations)->toBe(1);
    });

    test('activeStations excludes stations with only ended sessions', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        $station = Station::factory()->create([
            'event_configuration_id' => $config->id,
        ]);

        // Ended session only
        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'start_time' => appNow()->subHours(3),
            'end_time' => appNow()->subHours(1),
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->activeStations)->toBe(0);
    });

    test('activeStations is zero when no stations have sessions', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        Station::factory()->count(2)->create([
            'event_configuration_id' => $config->id,
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('view-equipment', 'web');
        $user->givePermissionTo('view-equipment');

        $this->actingAs($user);

        $component = Livewire::test(EquipmentStatus::class);

        expect($component->activeStations)->toBe(0);
    });
});
