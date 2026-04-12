<?php

namespace Tests\Unit\Services;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Station;
use App\Models\User;
use App\Notifications\Equipment\EquipmentCommitted;
use App\Services\StationCloneService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StationCloneServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StationCloneService $service;

    protected Event $sourceEvent;

    protected Event $targetEvent;

    protected EventConfiguration $sourceConfig;

    protected EventConfiguration $targetConfig;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new StationCloneService;

        // Create a user with manage-stations permission
        $this->user = User::factory()->create();
        Gate::define('manage-stations', fn (User $user) => $user->id === $this->user->id);
        Auth::login($this->user);

        // Create event type
        $eventType = EventType::create([
            'code' => 'fd',
            'name' => 'Field Day',
            'description' => 'Annual 24-hour emergency preparedness exercise',
            'is_active' => true,
        ]);

        // Create source event
        $this->sourceEvent = Event::factory()->create([
            'event_type_id' => $eventType->id,
            'name' => 'Field Day 2024',
            'start_time' => now()->addDays(30),
            'end_time' => now()->addDays(31),
        ]);

        $this->sourceConfig = EventConfiguration::factory()->create([
            'event_id' => $this->sourceEvent->id,
        ]);

        // Create target event (different time period)
        $this->targetEvent = Event::factory()->create([
            'event_type_id' => $eventType->id,
            'name' => 'Field Day 2025',
            'start_time' => now()->addDays(400),
            'end_time' => now()->addDays(401),
        ]);

        $this->targetConfig = EventConfiguration::factory()->create([
            'event_id' => $this->targetEvent->id,
        ]);
    }

    // Basic Cloning Tests

    public function test_can_clone_station_without_equipment(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Station 1A',
            'is_gota' => false,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => false]
        );

        expect($result['success'])->toBeTrue()
            ->and($result['stations_cloned'])->toBe(1)
            ->and($result['equipment_assigned'])->toBe(0)
            ->and($result['cloned_station_ids'])->toHaveCount(1);

        $clonedStation = Station::find($result['cloned_station_ids'][0]);
        expect($clonedStation->name)->toBe('Station 1A')
            ->and($clonedStation->event_configuration_id)->toBe($this->targetConfig->id)
            ->and($clonedStation->is_gota)->toBe($station->is_gota);
    }

    public function test_can_clone_station_with_equipment(): void
    {
        Notification::fake();

        $station = Station::withoutEvents(fn () => Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Station 2B',
        ]));

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'type' => 'antenna',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->sourceEvent->id,
            'station_id' => $station->id,
            'status' => 'committed',
            'assigned_by_user_id' => $this->user->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        expect($result['success'])->toBeTrue()
            ->and($result['stations_cloned'])->toBe(1)
            ->and($result['equipment_assigned'])->toBe(1)
            ->and($result['equipment_skipped'])->toBe(0);
    }

    public function test_can_clone_multiple_stations_at_once(): void
    {
        $station1 = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Station Alpha',
        ]);

        $station2 = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Station Beta',
        ]);

        $station3 = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Station Gamma',
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station1->id, $station2->id, $station3->id],
            ['copy_equipment' => false]
        );

        expect($result['success'])->toBeTrue()
            ->and($result['stations_cloned'])->toBe(3)
            ->and($result['cloned_station_ids'])->toHaveCount(3);
    }

    public function test_name_suffix_is_applied_correctly(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'HF Station',
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['name_suffix' => '(2025)']
        );

        expect($result['success'])->toBeTrue();

        $clonedStation = Station::find($result['cloned_station_ids'][0]);
        expect($clonedStation->name)->toBe('HF Station (2025)');
    }

    public function test_all_station_fields_are_copied_correctly(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Test Station',
            'is_gota' => false,
            'is_vhf_only' => true,
            'is_satellite' => true,
            'max_power_watts' => 100,
            'power_source_description' => 'Solar + Battery',
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id]
        );

        expect($result['success'])->toBeTrue();

        $clonedStation = Station::find($result['cloned_station_ids'][0]);
        expect($clonedStation->is_vhf_only)->toBe(true)
            ->and($clonedStation->is_satellite)->toBe(true)
            ->and($clonedStation->max_power_watts)->toBe(100)
            ->and($clonedStation->power_source_description)->toBe('Solar + Battery');
    }

    // Equipment Assignment Tests

    public function test_equipment_assignments_are_created_with_committed_status(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->sourceEvent->id,
            'station_id' => $station->id,
            'status' => 'committed',
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        expect($result['success'])->toBeTrue();

        $newAssignment = EquipmentEvent::where('event_id', $this->targetEvent->id)
            ->where('equipment_id', $equipment->id)
            ->first();

        expect($newAssignment)->not->toBeNull()
            ->and($newAssignment->status)->toBe('committed')
            ->and($newAssignment->committed_at)->not->toBeNull();
    }

    public function test_assigned_by_user_id_is_set_to_current_user(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->sourceEvent->id,
            'station_id' => $station->id,
            'status' => 'committed',
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        expect($result['success'])->toBeTrue();

        $newAssignment = EquipmentEvent::where('event_id', $this->targetEvent->id)
            ->where('equipment_id', $equipment->id)
            ->first();

        expect($newAssignment->assigned_by_user_id)->toBe($this->user->id)
            ->and($newAssignment->status_changed_by_user_id)->toBe($this->user->id);
    }

    public function test_equipment_already_committed_to_target_event_is_skipped(): void
    {
        Notification::fake();

        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'radio_equipment_id' => null, // Don't create primary radio
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        // Create equipment events without triggering observer
        EquipmentEvent::withoutEvents(function () use ($equipment, $station) {
            // Equipment assigned to source station
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->sourceEvent->id,
                'station_id' => $station->id,
                'status' => 'committed',
                'assigned_by_user_id' => $this->user->id,
            ]);

            // Equipment already committed to target event
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->targetEvent->id,
                'status' => 'committed',
            ]);
        });

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        // Note: Due to EquipmentEventObserver creating duplicate notifications when factories are used,
        // we sometimes see equipment counted twice. This is a known issue with the test setup.
        expect($result['success'])->toBeTrue()
            ->and($result['equipment_assigned'])->toBe(0)
            ->and($result['conflicts'][0]['reason'])->toContain('already committed to the target event');
    }

    public function test_deleted_equipment_is_skipped(): void
    {
        $station = Station::withoutEvents(fn () => Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]));

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->sourceEvent->id,
            'station_id' => $station->id,
            'status' => 'committed',
        ]);

        // Soft delete the equipment
        $equipment->delete();

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        // Deleted equipment is not loaded by the source query, so no conflicts or skips
        expect($result['success'])->toBeTrue()
            ->and($result['equipment_assigned'])->toBe(0)
            ->and($result['equipment_skipped'])->toBe(0);
    }

    public function test_equipment_in_overlapping_events_is_skipped(): void
    {
        Notification::fake();

        // Create overlapping event
        $overlappingEvent = Event::factory()->create([
            'event_type_id' => $this->sourceEvent->event_type_id,
            'start_time' => $this->targetEvent->start_time->copy()->subHours(12),
            'end_time' => $this->targetEvent->end_time->copy()->addHours(12),
        ]);

        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'radio_equipment_id' => null, // Don't create primary radio
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        EquipmentEvent::withoutEvents(function () use ($equipment, $station, $overlappingEvent) {
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->sourceEvent->id,
                'station_id' => $station->id,
                'status' => 'committed',
                'assigned_by_user_id' => $this->user->id,
            ]);

            // Equipment committed to overlapping event
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $overlappingEvent->id,
                'status' => 'committed',
            ]);
        });

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        // Note: Due to EquipmentEventObserver, we sometimes see equipment counted twice in tests
        expect($result['success'])->toBeTrue()
            ->and($result['equipment_assigned'])->toBe(0)
            ->and($result['conflicts'][0]['reason'])->toContain('overlapping event');
    }

    // Conflict Detection Tests

    public function test_preview_clone_detects_equipment_conflicts_without_creating_records(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Preview Station',
            'radio_equipment_id' => null,
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        EquipmentEvent::withoutEvents(function () use ($equipment, $station) {
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->sourceEvent->id,
                'station_id' => $station->id,
                'status' => 'committed',
                'assigned_by_user_id' => $this->user->id,
            ]);

            // Equipment already committed to target
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->targetEvent->id,
                'status' => 'committed',
            ]);
        });

        $initialStationCount = Station::where('event_configuration_id', $this->targetConfig->id)->count();

        $preview = $this->service->previewClone(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            true
        );

        $finalStationCount = Station::where('event_configuration_id', $this->targetConfig->id)->count();

        expect($preview['valid'])->toBeTrue()
            ->and($preview['stations'])->toHaveCount(1)
            ->and($initialStationCount)->toBe($finalStationCount); // No records created
    }

    public function test_detect_equipment_conflicts_returns_correct_conflict_details(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'radio_equipment_id' => null,
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'type' => 'radio',
            'make' => 'Icom',
            'model' => 'IC-7300',
        ]);

        EquipmentEvent::withoutEvents(function () use ($equipment, $station) {
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->sourceEvent->id,
                'station_id' => $station->id,
                'status' => 'committed',
                'assigned_by_user_id' => $this->user->id,
            ]);

            // Already committed to target
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->targetEvent->id,
                'status' => 'committed',
            ]);
        });

        $conflicts = $this->service->detectEquipmentConflicts($station, $this->targetConfig->id);

        expect($conflicts)->not->toBeEmpty()
            ->and($conflicts[0])->toHaveKeys(['equipment_id', 'equipment_type', 'make_model', 'reason'])
            ->and($conflicts[0]['equipment_id'])->toBe($equipment->id)
            ->and($conflicts[0]['equipment_type'])->toBe('radio')
            ->and($conflicts[0]['make_model'])->toBe('Icom IC-7300');
    }

    public function test_check_equipment_availability_correctly_identifies_unavailable_equipment(): void
    {
        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        // Equipment committed to target event
        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->targetEvent->id,
            'status' => 'committed',
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('checkEquipmentAvailability');
        $method->setAccessible(true);

        $availability = $method->invoke($this->service, $equipment->id, $this->targetEvent->id);

        expect($availability['available'])->toBeFalse()
            ->and($availability['reason'])->toContain('already committed to the target event');
    }

    // GOTA Handling Tests

    public function test_gota_station_cloned_to_event_without_gota_stays_gota(): void
    {
        $station = Station::factory()->gota()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id]
        );

        expect($result['success'])->toBeTrue();

        $clonedStation = Station::find($result['cloned_station_ids'][0]);
        expect($clonedStation->is_gota)->toBeTrue();
    }

    public function test_gota_station_cloned_to_event_with_existing_gota_becomes_non_gota(): void
    {
        // Create existing GOTA in target event
        Station::factory()->gota()->create([
            'event_configuration_id' => $this->targetConfig->id,
        ]);

        $sourceGotaStation = Station::factory()->gota()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$sourceGotaStation->id]
        );

        expect($result['success'])->toBeTrue();

        $clonedStation = Station::find($result['cloned_station_ids'][0]);
        expect($clonedStation->is_gota)->toBeFalse();
    }

    public function test_warning_is_added_when_gota_station_is_converted(): void
    {
        // Create existing GOTA in target event
        Station::factory()->gota()->create([
            'event_configuration_id' => $this->targetConfig->id,
        ]);

        $sourceGotaStation = Station::factory()->gota()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Source GOTA',
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$sourceGotaStation->id]
        );

        expect($result['success'])->toBeTrue()
            ->and($result['warnings'])->toHaveCount(1)
            ->and($result['warnings'][0])->toContain('Source GOTA')
            ->and($result['warnings'][0])->toContain('GOTA station')
            ->and($result['warnings'][0])->toContain('non-GOTA');
    }

    // Validation Tests

    public function test_throws_exception_if_source_event_not_found(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            99999, // Non-existent ID
            $this->targetConfig->id,
            [$station->id]
        );

        expect($result['success'])->toBeFalse()
            ->and($result['errors'])->toContain('Source event configuration not found.');
    }

    public function test_throws_exception_if_target_event_not_found(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            99999, // Non-existent ID
            [$station->id]
        );

        expect($result['success'])->toBeFalse()
            ->and($result['errors'])->toContain('Target event configuration not found.');
    }

    public function test_throws_exception_if_source_and_target_are_same_event(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->sourceConfig->id, // Same as source
            [$station->id]
        );

        expect($result['success'])->toBeFalse()
            ->and($result['errors'])->toContain('Source and target events must be different.');
    }

    public function test_throws_exception_if_station_id_not_found(): void
    {
        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [99999] // Non-existent station ID
        );

        expect($result['success'])->toBeFalse()
            ->and($result['errors'])->toContain('One or more station IDs do not exist in the source event.');
    }

    public function test_throws_exception_if_user_lacks_manage_stations_permission(): void
    {
        $unauthorizedUser = User::factory()->create();
        Auth::login($unauthorizedUser);

        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id]
        );

        expect($result['success'])->toBeFalse()
            ->and($result['errors'][0])->toContain('Permission denied');
    }

    // Transaction Tests

    public function test_rollback_on_error(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        // Mock DB::transaction to simulate an error
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new \Exception('Simulated error'));

        $initialStationCount = Station::where('event_configuration_id', $this->targetConfig->id)->count();

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id]
        );

        $finalStationCount = Station::where('event_configuration_id', $this->targetConfig->id)->count();

        expect($result['success'])->toBeFalse()
            ->and($result['errors'])->not->toBeEmpty()
            ->and($initialStationCount)->toBe($finalStationCount); // No partial clones
    }

    public function test_all_stations_cloned_or_none(): void
    {
        $station1 = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $station2 = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $equipment1 = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        $equipment2 = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
        ]);

        // Add different equipment to each station to avoid unique constraint violation
        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment1->id,
            'event_id' => $this->sourceEvent->id,
            'station_id' => $station1->id,
            'status' => 'committed',
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment2->id,
            'event_id' => $this->sourceEvent->id,
            'station_id' => $station2->id,
            'status' => 'committed',
        ]);

        // Both stations should clone successfully
        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station1->id, $station2->id],
            ['copy_equipment' => true, 'skip_conflicts' => true]
        );

        expect($result['success'])->toBeTrue()
            ->and($result['stations_cloned'])->toBe(2);
    }

    // Notification Tests

    public function test_equipment_committed_notification_sent_to_equipment_owners(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->sourceEvent->id,
            'station_id' => $station->id,
            'status' => 'committed',
        ]);

        $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        Notification::assertSentTo(
            $owner,
            EquipmentCommitted::class
        );
    }

    public function test_notifications_are_queued(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        EquipmentEvent::factory()->create([
            'equipment_id' => $equipment->id,
            'event_id' => $this->sourceEvent->id,
            'station_id' => $station->id,
            'status' => 'committed',
        ]);

        $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        Notification::assertSentTo(
            $owner,
            EquipmentCommitted::class,
            function ($notification) {
                return $notification instanceof ShouldQueue;
            }
        );
    }

    public function test_no_notification_sent_if_copy_equipment_false(): void
    {
        $owner = User::factory()->create();
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'radio_equipment_id' => null,
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => $owner->id,
        ]);

        // Create equipment event without triggering observer notifications
        EquipmentEvent::withoutEvents(function () use ($equipment, $station) {
            return EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->sourceEvent->id,
                'station_id' => $station->id,
                'status' => 'committed',
                'assigned_by_user_id' => $this->user->id,
            ]);
        });

        // NOW fake notifications to test the clone service
        Notification::fake();

        $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => false]
        );

        // When copy_equipment is false, no equipment notifications should be sent
        Notification::assertNothingSent();
    }

    // Return Value Tests

    public function test_returns_correct_success_status(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id]
        );

        expect($result)->toHaveKey('success')
            ->and($result['success'])->toBeTrue();
    }

    public function test_returns_correct_station_count(): void
    {
        $stations = Station::factory()->count(3)->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            $stations->pluck('id')->toArray()
        );

        expect($result['stations_cloned'])->toBe(3);
    }

    public function test_returns_correct_equipment_counts(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'radio_equipment_id' => null, // Don't create primary radio
        ]);

        EquipmentEvent::withoutEvents(function () use ($station) {
            // Create 2 available equipment
            for ($i = 0; $i < 2; $i++) {
                $equipment = Equipment::factory()->create([
                    'owner_user_id' => User::factory()->create()->id,
                ]);

                EquipmentEvent::factory()->create([
                    'equipment_id' => $equipment->id,
                    'event_id' => $this->sourceEvent->id,
                    'station_id' => $station->id,
                    'status' => 'committed',
                    'assigned_by_user_id' => $this->user->id,
                ]);
            }

            // Create 1 conflicting equipment (already committed to target)
            $conflictEquipment = Equipment::factory()->create([
                'owner_user_id' => User::factory()->create()->id,
            ]);

            EquipmentEvent::factory()->create([
                'equipment_id' => $conflictEquipment->id,
                'event_id' => $this->sourceEvent->id,
                'station_id' => $station->id,
                'status' => 'committed',
                'assigned_by_user_id' => $this->user->id,
            ]);

            EquipmentEvent::factory()->create([
                'equipment_id' => $conflictEquipment->id,
                'event_id' => $this->targetEvent->id,
                'status' => 'committed',
            ]);
        });

        Notification::fake();

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        expect($result['equipment_assigned'])->toBe(2)
            ->and($result['equipment_skipped'])->toBeGreaterThanOrEqual(1);
    }

    public function test_returns_conflict_details_array(): void
    {
        $station = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
            'name' => 'Conflict Test Station',
            'radio_equipment_id' => null,
        ]);

        $equipment = Equipment::factory()->create([
            'owner_user_id' => User::factory()->create()->id,
            'type' => 'radio',
            'make' => 'Yaesu',
            'model' => 'FT-991A',
        ]);

        // Create equipment events without triggering observer notifications
        EquipmentEvent::withoutEvents(function () use ($equipment, $station) {
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->sourceEvent->id,
                'station_id' => $station->id,
                'status' => 'committed',
                'assigned_by_user_id' => $this->user->id,
            ]);

            // Create conflict
            EquipmentEvent::factory()->create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->targetEvent->id,
                'status' => 'committed',
            ]);
        });

        Notification::fake();

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station->id],
            ['copy_equipment' => true]
        );

        expect($result['conflicts'])->toBeArray()
            ->and($result['conflicts'][0])->toHaveKeys(['equipment_id', 'equipment_type', 'make_model', 'reason', 'station_name'])
            ->and($result['conflicts'][0]['station_name'])->toBe('Conflict Test Station');
    }

    public function test_returns_cloned_station_ids(): void
    {
        $station1 = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $station2 = Station::factory()->create([
            'event_configuration_id' => $this->sourceConfig->id,
        ]);

        $result = $this->service->cloneStations(
            $this->sourceConfig->id,
            $this->targetConfig->id,
            [$station1->id, $station2->id]
        );

        expect($result['cloned_station_ids'])->toBeArray()
            ->and($result['cloned_station_ids'])->toHaveCount(2)
            ->and($result['cloned_station_ids'][0])->toBeInt()
            ->and($result['cloned_station_ids'][1])->toBeInt();
    }
}
