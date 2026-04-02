<?php

namespace Tests\Unit\Services;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Station;
use App\Models\User;
use App\Services\EquipmentReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected EquipmentReportService $service;

    protected Event $event;

    protected User $owner;

    protected Station $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EquipmentReportService;

        // Create test data
        $eventType = EventType::create([
            'code' => 'fd',
            'name' => 'Field Day',
            'description' => 'Annual 24-hour emergency preparedness exercise',
            'is_active' => true,
        ]);

        $this->event = Event::factory()->create([
            'event_type_id' => $eventType->id,
            'start_time' => now()->addDays(7),
            'end_time' => now()->addDays(8),
        ]);

        $eventConfig = EventConfiguration::factory()->create([
            'event_id' => $this->event->id,
        ]);

        $this->station = Station::factory()->create([
            'event_configuration_id' => $eventConfig->id,
            'name' => 'Station 1A',
        ]);

        $this->owner = User::factory()->create([
            'call_sign' => 'W1AW',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    public function test_generate_commitment_summary_returns_structured_data(): void
    {
        // Create equipment and commitment
        $equipment = Equipment::factory()->create([
            'owner_user_id' => $this->owner->id,
            'type' => 'radio',
            'make' => 'Icom',
            'model' => 'IC-7300',
            'value_usd' => 1200.00,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->event->id,
            'status' => 'committed',
            'expected_delivery_at' => now()->addDays(7),
        ]);

        $report = $this->service->generateCommitmentSummary($this->event->id);

        expect($report)->toBeArray()
            ->and($report)->toHaveKeys(['event', 'summary', 'equipment_by_type', 'delivery_timeline', 'contacts'])
            ->and($report['summary'])->toHaveKeys(['total_items', 'total_value', 'by_type', 'by_status'])
            ->and($report['summary']['total_items'])->toBe(1)
            ->and($report['summary']['total_value'])->toBe('1,200.00');
    }

    public function test_generate_delivery_checklist_returns_sorted_items(): void
    {
        $equipment1 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);
        $equipment2 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
            'status' => 'committed',
            'expected_delivery_at' => now()->addDays(7)->addHours(2),
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
            'status' => 'committed',
            'expected_delivery_at' => now()->addDays(7)->addHours(1),
        ]);

        $report = $this->service->generateDeliveryChecklist($this->event->id);

        expect($report)->toHaveKeys(['event', 'checklist_items'])
            ->and($report['checklist_items'])->toHaveCount(2)
            ->and($report['checklist_items'][0])->toHaveKey('checkbox')
            ->and($report['checklist_items'][0])->toHaveKey('signature_line');
    }

    public function test_generate_station_inventory_groups_by_station(): void
    {
        $equipment1 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);
        $equipment2 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
            'station_id' => $this->station->id,
            'status' => 'delivered',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
            'station_id' => null,
            'status' => 'delivered',
        ]);

        $report = $this->service->generateStationInventory($this->event->id);

        expect($report)->toHaveKeys(['event', 'stations', 'unassigned_equipment'])
            ->and($report['stations'])->toHaveCount(1)
            ->and($report['unassigned_equipment'])->toHaveCount(1);
    }

    public function test_generate_owner_contact_list_groups_by_owner(): void
    {
        $equipment1 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);
        $equipment2 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
        ]);

        $report = $this->service->generateOwnerContactList($this->event->id);

        expect($report)->toHaveKeys(['event', 'contacts'])
            ->and($report['contacts'])->toHaveCount(1)
            ->and($report['contacts'][0])->toHaveKeys(['owner_name', 'callsign', 'email', 'equipment_count'])
            ->and($report['contacts'][0]['equipment_count'])->toBe(2);
    }

    public function test_generate_return_checklist_includes_only_delivered(): void
    {
        $equipment1 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);
        $equipment2 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
            'status' => 'delivered',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
            'status' => 'returned',
        ]);

        $report = $this->service->generateReturnChecklist($this->event->id);

        expect($report)->toHaveKeys(['event', 'return_items', 'summary'])
            ->and($report['return_items'])->toHaveCount(1)
            ->and($report['summary']['total_items'])->toBe(1);
    }

    public function test_generate_incident_report_includes_lost_damaged_cancelled(): void
    {
        $equipment1 = Equipment::factory()->create([
            'owner_user_id' => $this->owner->id,
            'value_usd' => 500.00,
        ]);
        $equipment2 = Equipment::factory()->create([
            'owner_user_id' => $this->owner->id,
            'value_usd' => 300.00,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
            'status' => 'lost',
            'manager_notes' => 'Equipment missing after event',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
            'status' => 'damaged',
            'manager_notes' => 'Antenna fell during storm',
        ]);

        $report = $this->service->generateIncidentReport($this->event->id);

        expect($report)->toHaveKeys(['event', 'incidents', 'summary'])
            ->and($report['incidents'])->toHaveCount(2)
            ->and($report['summary']['total_incidents'])->toBe(2)
            ->and($report['summary']['total_value_at_risk'])->toBe('800.00');
    }

    public function test_generate_historical_record_includes_all_equipment(): void
    {
        $equipment1 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);
        $equipment2 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);
        $equipment3 = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->event->id,
            'status' => 'returned',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->event->id,
            'status' => 'delivered',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment3->id,
            'event_id' => $this->event->id,
            'status' => 'cancelled',
        ]);

        $report = $this->service->generateHistoricalRecord($this->event->id);

        expect($report)->toHaveKeys(['event', 'equipment_records', 'summary'])
            ->and($report['equipment_records'])->toHaveCount(3)
            ->and($report['summary']['total_equipment'])->toBe(3)
            ->and($report['summary'])->toHaveKey('success_rate');
    }

    public function test_reports_handle_empty_data_gracefully(): void
    {
        $report = $this->service->generateCommitmentSummary($this->event->id);

        expect($report['summary']['total_items'])->toBe(0)
            ->and($report['summary']['total_value'])->toBe('0.00');
    }

    public function test_commitment_summary_includes_delivery_timeline(): void
    {
        $equipment = Equipment::factory()->create(['owner_user_id' => $this->owner->id]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->event->id,
            'status' => 'committed',
            'expected_delivery_at' => now()->addDays(7)->setTime(10, 0),
        ]);

        $report = $this->service->generateCommitmentSummary($this->event->id);

        expect($report['delivery_timeline'])->not->toBeEmpty();
    }
}
