<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Dashboard\Widgets\ListWidget;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Create active event (using appNow() for date-based activation)
    $this->event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    Cache::flush();
});

describe('ListWidget Component', function () {
    it('renders successfully with default config', function () {
        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
            'size' => 'normal',
        ])
            ->assertStatus(200)
            ->assertViewIs('livewire.dashboard.widgets.list-widget');
    });

    it('uses IsWidget trait', function () {
        $widget = new ListWidget;

        expect($widget)->toHaveProperty('size')
            ->and($widget)->toHaveProperty('config')
            ->and($widget)->toHaveProperty('widgetId')
            ->and(method_exists($widget, 'getData'))->toBeTrue()
            ->and(method_exists($widget, 'getWidgetListeners'))->toBeTrue();
    });

    it('returns correct listeners for recent_contacts', function () {
        config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ]);

        expect($component->instance()->getWidgetListeners())->toBe([
            "echo-private:event.{$this->event->id},.ContactLogged" => 'handleUpdate',
            "echo-private:event.{$this->event->id},.ContactUpdated" => 'handleUpdate',
            "echo-private:event.{$this->event->id},.ContactDeleted" => 'handleUpdate',
        ]);

        // Guests (the public TV) cannot join the private event channel and rely on polling.
        auth()->logout();

        expect($component->instance()->getWidgetListeners())->toBe([]);
    });

    it('generates unique cache key', function () {
        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ]);

        $cacheKey = $component->instance()->cacheKey();

        expect($cacheKey)
            ->toBeString()
            ->toContain('dashboard:widget:ListWidget')
            ->toContain((string) $this->event->id);
    });
});

describe('Recent Contacts List', function () {
    it('returns empty array when no event is active', function () {
        // Delete the active event
        $this->event->delete();

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ]);

        $data = $component->instance()->getData();

        expect($data)
            ->toBeArray()
            ->toHaveKeys(['items', 'last_updated_at'])
            ->and($data['items'])->toBeEmpty()
            ->and($data['last_updated_at'])->toBeInstanceOf(Carbon::class);
    });

    it('fetches recent contacts with correct data structure', function () {
        $band = Band::factory()->create(['name' => '20m']);
        $mode = Mode::factory()->create(['name' => 'SSB']);
        $operator = User::factory()->create(['call_sign' => 'K3CPK']);
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Alpha',
        ]);

        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $operator->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'callsign' => 'W1AW',
            'qso_time' => appNow()->subMinutes(5),
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ]);

        $data = $component->instance()->getData();

        expect($data)
            ->toHaveKeys(['items', 'last_updated_at'])
            ->and($data['items'])->toHaveCount(1)
            ->and($data['items'][0])->toHaveKeys(['type', 'time_ago', 'callsign', 'band', 'mode', 'operator', 'station'])
            ->and($data['items'][0]['type'])->toBe('recent_contact')
            ->and($data['items'][0]['callsign'])->toBe('W1AW')
            ->and($data['items'][0]['band'])->toBe('20m')
            ->and($data['items'][0]['mode'])->toBe('SSB')
            ->and($data['items'][0]['operator'])->toBe('K3CPK')
            ->and($data['items'][0]['station'])->toBe('Station Alpha')
            ->and($data['last_updated_at'])->toBeInstanceOf(Carbon::class);
    });

    it('limits recent contacts to 15 for normal size', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        // Create 20 contacts
        Contact::factory()->count(20)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
            'size' => 'normal',
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(15);
    });

    it('honours item_count config override for recent contacts', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        Contact::factory()->count(20)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts', 'item_count' => '5'],
            'size' => 'normal',
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(5);
    });

    it('limits recent contacts to 10 for tv size', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        // Create 20 contacts
        Contact::factory()->count(20)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
            'size' => 'tv',
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(10);
    });

    it('excludes duplicate contacts', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        // Create original contact
        $original = Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'is_duplicate' => false,
        ]);

        // Create duplicate contact
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'is_duplicate' => true,
            'duplicate_of_contact_id' => $original->id,
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ]);

        $data = $component->instance()->getData();

        // Should only return the original, not the duplicate
        expect($data['items'])->toHaveCount(1);
    });

    it('formats time ago correctly', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        // Contact 5 minutes ago
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'callsign' => 'W1AW',
            'qso_time' => appNow()->subMinutes(5),
        ]);

        // Contact 2 hours ago
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'callsign' => 'K3CPK',
            'qso_time' => appNow()->subHours(2),
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(2)
            ->and($data['items'][0]['time_ago'])->toContain('m ago') // Most recent (5 minutes)
            ->and($data['items'][1]['time_ago'])->toContain('h ago'); // Older (2 hours)
    });
});

describe('Active Stations List', function () {
    it('fetches active stations with correct data structure', function () {
        $band = Band::factory()->create(['name' => '40m']);
        $mode = Mode::factory()->create(['name' => 'CW']);
        $operator = User::factory()->create(['call_sign' => 'N3XYZ']);
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Bravo',
        ]);

        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $operator->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'start_time' => appNow()->subHour(),
            'end_time' => null, // Active session
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'active_stations'],
        ]);

        $data = $component->instance()->getData();

        expect($data)
            ->toHaveKeys(['items', 'last_updated_at'])
            ->and($data['items'])->toHaveCount(1)
            ->and($data['items'][0])->toHaveKeys(['type', 'station_name', 'operator_name', 'band', 'mode', 'status', 'status_color'])
            ->and($data['items'][0]['type'])->toBe('active_station')
            ->and($data['items'][0]['station_name'])->toBe('Station Bravo')
            ->and($data['items'][0]['operator_name'])->toBe('N3XYZ')
            ->and($data['items'][0]['band'])->toBe('40m')
            ->and($data['items'][0]['mode'])->toBe('CW')
            ->and($data['items'][0]['status'])->toBe('Active')
            ->and($data['items'][0]['status_color'])->toBe('success');
    });

    it('only shows active sessions not ended sessions', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        // Active session (end_time is null)
        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'start_time' => appNow()->subHour(),
            'end_time' => null,
        ]);

        // Ended session
        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'start_time' => appNow()->subHours(3),
            'end_time' => appNow()->subHours(2),
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'active_stations'],
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(1);
    });

    it('limits active stations to 15 for normal size', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();

        // Create 20 active sessions
        for ($i = 0; $i < 20; $i++) {
            $station = Station::factory()->create([
                'event_configuration_id' => $this->eventConfig->id,
            ]);

            OperatingSession::factory()->create([
                'station_id' => $station->id,
                'band_id' => $band->id,
                'mode_id' => $mode->id,
                'end_time' => null,
            ]);
        }

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'active_stations'],
            'size' => 'normal',
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(15);
    });

    it('limits active stations to 10 for tv size', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();

        // Create 20 active sessions
        for ($i = 0; $i < 20; $i++) {
            $station = Station::factory()->create([
                'event_configuration_id' => $this->eventConfig->id,
            ]);

            OperatingSession::factory()->create([
                'station_id' => $station->id,
                'band_id' => $band->id,
                'mode_id' => $mode->id,
                'end_time' => null,
            ]);
        }

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'active_stations'],
            'size' => 'tv',
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(10);
    });
});

describe('Equipment Status List', function () {
    it('fetches equipment status with correct data structure', function () {
        $equipment = Equipment::factory()->create([
            'make' => 'Yaesu',
            'model' => 'FT-991A',
        ]);

        $station = Station::withoutEvents(fn () => Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Charlie',
        ]));

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->event->id,
            'station_id' => $station->id,
            'status' => 'delivered',
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
        ]);

        $data = $component->instance()->getData();

        expect($data)
            ->toHaveKeys(['items', 'last_updated_at'])
            ->and($data['items'])->toHaveCount(1)
            ->and($data['items'][0])->toHaveKeys(['type', 'equipment_name', 'status', 'status_color', 'assigned_to'])
            ->and($data['items'][0]['type'])->toBe('equipment')
            ->and($data['items'][0]['equipment_name'])->toBe('Yaesu FT-991A')
            ->and($data['items'][0]['status'])->toBe('Delivered')
            ->and($data['items'][0]['status_color'])->toBe('info')
            ->and($data['items'][0]['assigned_to'])->toBe('Station Charlie');
    });

    it('shows unassigned for equipment without station', function () {
        $equipment = Equipment::factory()->create([
            'make' => 'Icom',
            'model' => 'IC-7300',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->event->id,
            'station_id' => null,
            'status' => 'committed',
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(1)
            ->and($data['items'][0]['assigned_to'])->toBe('Unassigned');
    });

    it('only shows active equipment statuses', function () {
        $equipment1 = Equipment::factory()->create();
        $equipment2 = Equipment::factory()->create();
        $equipment3 = Equipment::factory()->create();

        // Active statuses
        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
            'status' => 'committed',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
            'status' => 'delivered',
        ]);

        // Inactive status - should not appear
        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment3->id,
            'event_id' => $this->event->id,
            'status' => 'returned',
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(2);
    });

    it('orders equipment by priority status', function () {
        $equipment1 = Equipment::factory()->create(['make' => 'A', 'model' => 'Committed']);
        $equipment2 = Equipment::factory()->create(['make' => 'B', 'model' => 'Delivered']);

        // Create in non-priority order
        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
            'status' => 'committed',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
            'status' => 'delivered',
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
        ]);

        $data = $component->instance()->getData();

        // Should be ordered: delivered, committed
        expect($data['items'])->toHaveCount(2)
            ->and($data['items'][0]['equipment_name'])->toContain('Delivered')
            ->and($data['items'][1]['equipment_name'])->toContain('Committed');
    });

    it('limits equipment to 15 for normal size', function () {
        // Create 20 equipment commitments
        for ($i = 0; $i < 20; $i++) {
            $equipment = Equipment::factory()->create();
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->event->id,
                'status' => 'delivered',
            ]);
        }

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
            'size' => 'normal',
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(15);
    });

    it('limits equipment to 10 for tv size', function () {
        // Create 20 equipment commitments
        for ($i = 0; $i < 20; $i++) {
            $equipment = Equipment::factory()->create();
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->event->id,
                'status' => 'delivered',
            ]);
        }

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
            'size' => 'tv',
        ]);

        $data = $component->instance()->getData();

        expect($data['items'])->toHaveCount(10);
    });

    it('maps status to correct colors', function () {
        $equipment1 = Equipment::factory()->create(['make' => 'A', 'model' => '1']);
        $equipment2 = Equipment::factory()->create(['make' => 'B', 'model' => '2']);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
            'status' => 'delivered',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
            'status' => 'committed',
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
        ]);

        $data = $component->instance()->getData();

        expect($data['items'][0]['status_color'])->toBe('info') // delivered
            ->and($data['items'][1]['status_color'])->toBe('warning'); // committed
    });
});

describe('Caching', function () {
    it('caches data for 3 seconds', function () {
        $band = Band::factory()->create();
        $mode = Mode::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'callsign' => 'W1AW',
        ]);

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ]);

        $data1 = $component->instance()->getData();

        // Create another contact after first data fetch
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'callsign' => 'K3CPK',
        ]);

        $data2 = $component->instance()->getData();

        // Should still return cached data (1 contact)
        expect($data1['items'])->toHaveCount(1)
            ->and($data2['items'])->toHaveCount(1);

        // Move past the 3-second cache TTL and fetch again
        $this->travel(4)->seconds();
        $data3 = $component->instance()->getData();

        // Now should return fresh data (2 contacts)
        expect($data3['items'])->toHaveCount(2);
    });
});

describe('View Rendering', function () {
    it('renders recent contacts list in view', function () {
        $band = Band::factory()->create(['name' => '20m']);
        $mode = Mode::factory()->create(['name' => 'SSB']);
        $operator = User::factory()->create(['call_sign' => 'K3CPK']);
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);
        $session = OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $operator->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
        ]);

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'operating_session_id' => $session->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'callsign' => 'W1AW',
            'qso_time' => appNow()->subMinutes(5),
        ]);

        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ])
            ->assertSee('W1AW')
            ->assertSee('20m')
            ->assertSee('SSB')
            ->assertSee('K3CPK');
    });

    it('renders active stations list in view', function () {
        $band = Band::factory()->create(['name' => '40m']);
        $mode = Mode::factory()->create(['name' => 'CW']);
        $operator = User::factory()->create(['call_sign' => 'N3XYZ']);
        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Alpha',
        ]);

        OperatingSession::factory()->create([
            'station_id' => $station->id,
            'operator_user_id' => $operator->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'end_time' => null,
        ]);

        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'active_stations'],
        ])
            ->assertSee('Station Alpha')
            ->assertSee('N3XYZ')
            ->assertSee('40m')
            ->assertSee('CW')
            ->assertSee('Active');
    });

    it('renders equipment status list in view', function () {
        $equipment = Equipment::factory()->create([
            'make' => 'Yaesu',
            'model' => 'FT-991A',
        ]);

        $station = Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'name' => 'Station Bravo',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->event->id,
            'station_id' => $station->id,
            'status' => 'delivered',
        ]);

        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
        ])
            ->assertSee('Yaesu FT-991A')
            ->assertSee('Station Bravo');
    });

    it('shows empty state when no data available', function () {
        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ])
            ->assertSee('No contacts logged yet')
            ->assertSee('Start making contacts to see them appear here');
    });

    it('applies tv size classes correctly', function () {
        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
            'size' => 'tv',
        ])
            ->assertSee('Recent Contacts') // Widget renders with tv size
            ->assertViewHas('size', 'tv');
    });

    it('applies normal size classes correctly', function () {
        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
            'size' => 'normal',
        ])
            ->assertSee('Recent Contacts') // Widget renders with normal size
            ->assertViewHas('size', 'normal');
    });
});

describe('ListWidget list type access', function () {
    beforeEach(function () {
        $equipment = Equipment::factory()->create(['make' => 'Secret', 'model' => 'Rig']);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->event->id,
            'status' => 'delivered',
        ]);
    });

    it('does not show the equipment list to guests', function () {
        auth()->logout();

        $component = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
            'size' => 'tv',
        ]);

        $items = $component->instance()->getData()['items'];

        expect(collect($items)->pluck('type')->all())->not->toContain('equipment')
            ->and($component->viewData('listType'))->toBe('recent_contacts');
    });

    it('does not serve a cached equipment list to guests', function () {
        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
        ])->instance()->getData();

        auth()->logout();

        $items = Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'equipment_status'],
        ])->instance()->getData()['items'];

        expect(collect($items)->pluck('type')->all())->not->toContain('equipment');
    });

    it('falls back to recent contacts for unknown list types', function () {
        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'users_table'],
        ])->assertViewHas('listType', 'recent_contacts');
    });

    it('does not let the browser change the widget config', function () {
        Livewire::test(ListWidget::class, [
            'config' => ['list_type' => 'recent_contacts'],
        ])->set('config.list_type', 'equipment_status');
    })->throws(CannotUpdateLockedPropertyException::class);
});

describe('net traffic list', function () {
    it('shows the newest net log entries with priority', function () {
        NetLogEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'from_station' => 'Aid 7',
            'from_callsign' => 'W1ABC',
            'to_station' => 'Net Control',
            'message' => 'Older call',
            'logged_at' => appNow()->subMinutes(20),
        ]);
        NetLogEntry::factory()->emergency()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'message' => 'Runner down at mile 14',
            'logged_at' => appNow()->subMinute(),
        ]);

        $component = Livewire::test(ListWidget::class, ['config' => ['list_type' => 'net_traffic']]);
        $items = $component->viewData('items');

        expect($items)->toHaveCount(2)
            ->and($items[0]['message'])->toBe('Runner down at mile 14')
            ->and($items[0]['priority'])->toBe('emergency')
            ->and($items[1]['from'])->toBe('Aid 7 (W1ABC)');

        $component->assertSee('Net Traffic')->assertSee('Emergency')->assertSee('Runner down at mile 14');
    });

    it('names the net only when the event runs more than one', function () {
        $local = Net::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Local 2m Net']);
        NetLogEntry::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'net_id' => $local->id]);

        expect(Livewire::test(ListWidget::class, ['config' => ['list_type' => 'net_traffic']])->viewData('items')[0]['net'])->toBeNull();

        $hf = Net::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'name' => 'Regional HF Net']);
        NetLogEntry::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'net_id' => $hf->id, 'logged_at' => appNow()->addMinute()]);
        Cache::flush();

        Livewire::test(ListWidget::class, ['config' => ['list_type' => 'net_traffic']])
            ->assertSeeInOrder(['Regional HF Net', 'Local 2m Net']);
    });

    it('shows an empty state when nothing is logged', function () {
        Livewire::test(ListWidget::class, ['config' => ['list_type' => 'net_traffic']])
            ->assertSee('No net traffic yet');
    });

    it('refreshes live when a net log entry changes', function () {
        config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']);

        $component = Livewire::test(ListWidget::class, ['config' => ['list_type' => 'net_traffic']]);

        expect($component->instance()->getWidgetListeners())->toBe([
            "echo-private:event.{$this->event->id},.NetLogChanged" => 'handleUpdate',
        ]);
    });

    it('never shows net traffic to guests on the public TV', function () {
        NetLogEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'message' => 'Patient details',
        ]);
        auth()->logout();

        Livewire::test(ListWidget::class, ['config' => ['list_type' => 'net_traffic']])
            ->assertDontSee('Patient details')
            ->assertDontSee('Net Traffic');
    });
});

describe('station status list', function () {
    beforeEach(function () {
        $this->net = Net::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'overdue_after_minutes' => 30]);
        $this->station = fn (string $tactical, ?string $location = null) => Station::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'tactical_callsign' => $tactical,
            'location' => $location,
        ]);
        $this->heard = fn (string $from, int $minutesAgo) => NetLogEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'net_id' => $this->net->id,
            'from_station' => $from,
            'logged_at' => appNow()->subMinutes($minutesAgo),
        ]);
    });

    it('lists net positions with location, last heard and overdue stations first', function () {
        ($this->station)('Aid 2', 'Mile 2');
        ($this->station)('Aid 7', 'Mile 12');
        ($this->station)('Aid 9');
        ($this->heard)('Aid 2', 5);
        ($this->heard)('Aid 7', 45);

        $component = Livewire::test(ListWidget::class, ['config' => ['list_type' => 'station_status']]);
        $items = $component->viewData('items');

        expect(array_column($items, 'name'))->toBe(['Aid 7', 'Aid 9', 'Aid 2'])
            ->and($items[0]['overdue'])->toBeTrue()
            ->and($items[0]['last_heard'])->toBe('45m ago')
            ->and($items[2]['overdue'])->toBeFalse();

        $component->assertSee('Station Status')->assertSee('Mile 12')->assertSee('Overdue')->assertSee('No operator on duty');
    });

    it('uses the overdue threshold of the net with the latest traffic', function () {
        ($this->station)('Aid 7');
        ($this->heard)('Aid 7', 45);
        $quietNet = Net::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'overdue_after_minutes' => null]);
        NetLogEntry::factory()->create(['event_configuration_id' => $this->eventConfig->id, 'net_id' => $quietNet->id, 'from_station' => 'NCS', 'logged_at' => appNow()]);

        expect(Livewire::test(ListWidget::class, ['config' => ['list_type' => 'station_status']])->viewData('items')[0]['overdue'])->toBeFalse();
    });

    it('shows an empty state when no station has a tactical callsign or location', function () {
        Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

        Livewire::test(ListWidget::class, ['config' => ['list_type' => 'station_status']])
            ->assertSee('No stations to show')
            ->assertSee('Stations with a tactical callsign or location appear here');
    });

    it('refreshes live when the net log changes', function () {
        config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']);

        expect(Livewire::test(ListWidget::class, ['config' => ['list_type' => 'station_status']])->instance()->getWidgetListeners())->toBe([
            "echo-private:event.{$this->event->id},.NetLogChanged" => 'handleUpdate',
        ]);
    });

    it('never shows where stations are to guests on the public TV', function () {
        ($this->station)('Aid 7', 'Behind the fire hall');
        auth()->logout();

        Livewire::test(ListWidget::class, ['config' => ['list_type' => 'station_status']])
            ->assertDontSee('Behind the fire hall')
            ->assertDontSee('Station Status');
    });
});
