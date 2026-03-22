<?php

namespace App\Console\Commands;

use App\Models\Band;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;

class SeedPlaywrightData extends Command
{
    protected $signature = 'app:seed-playwright-data {scenario}';

    protected $description = 'Seed test data for Playwright E2E tests and output JSON with IDs';

    public function handle(): int
    {
        // Clear ALL login rate limiters to avoid 429 during rapid test runs
        $email = 'playwright-test@example.com';
        RateLimiter::clear($email);
        RateLimiter::clear(strtolower($email).'|'.request()?->ip());
        // Fortify uses SHA-256 hash of email|ip
        RateLimiter::clear(sha1(strtolower($email).'|'.request()?->ip()));
        // Also clear by just the IP
        if (request()?->ip()) {
            RateLimiter::clear(request()->ip());
        }
        // Nuclear option: clear all cache with login prefix
        try {
            cache()->flush();
        } catch (\Exception $e) {
            // Ignore if cache doesn't support flush
        }

        $scenario = $this->argument('scenario');

        $result = match ($scenario) {
            'equipment-warning-band' => $this->seedBandWarningScenario(),
            'equipment-warning-power' => $this->seedPowerWarningScenario(),
            'equipment-no-warning' => $this->seedNoWarningScenario(),
            'station-update' => $this->seedStationUpdateScenario(),
            'schedule-manage' => $this->seedScheduleManageScenario(),
            'schedule-signup' => $this->seedScheduleSignupScenario(),
            'schedule-checkin' => $this->seedScheduleCheckinScenario(),
            'cleanup' => $this->cleanup(),
            default => null,
        };

        if ($result === null) {
            $this->error("Unknown scenario: {$scenario}");

            return self::FAILURE;
        }

        $this->line(json_encode($result));

        return self::SUCCESS;
    }

    /**
     * Create shared infrastructure for all scenarios.
     *
     * @return array{user: User, event: Event, eventConfig: EventConfiguration, station: Station, radio: Equipment}
     */
    private function createInfrastructure(int $maxPowerWatts = 150): array
    {
        $permissions = [
            'manage-stations', 'view-all-equipment', 'manage-own-equipment',
            'view-stations', 'view-events', 'manage-event-equipment',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $user = User::factory()->create([
            'email' => 'playwright-test@example.com',
            'password' => Hash::make('playwright-test-password'),
            'call_sign' => 'PW1TST',
            'first_name' => 'Playwright',
            'last_name' => 'Tester',
        ]);
        $user->givePermissionTo($permissions);

        $eventType = EventType::firstOrCreate(
            ['code' => 'FD'],
            [
                'name' => 'Field Day',
                'description' => 'ARRL Field Day',
                'is_active' => true,
            ]
        );

        $section = Section::firstOrCreate(
            ['code' => 'CO'],
            [
                'name' => 'Colorado',
                'region' => 'W0',
                'country' => 'US',
                'is_active' => true,
            ]
        );

        $operatingClass = OperatingClass::firstOrCreate(
            ['code' => '3A', 'event_type_id' => $eventType->id],
            [
                'name' => 'Class 3A',
                'allows_gota' => true,
                'max_power_watts' => $maxPowerWatts,
                'requires_emergency_power' => false,
            ]
        );

        // Make the test event span appNow (respects developer clock override)
        Event::query()->update(['is_current' => false]);

        $event = Event::factory()->create([
            'event_type_id' => $eventType->id,
            'is_active' => true,
            'is_current' => true,
            'name' => 'PW Test Field Day',
            'start_time' => appNow()->subHours(6),
            'end_time' => appNow()->addHours(21),
        ]);

        $eventConfig = EventConfiguration::factory()->create([
            'event_id' => $event->id,
            'section_id' => $section->id,
            'operating_class_id' => $operatingClass->id,
        ]);

        $hfBand = Band::firstOrCreate(
            ['name' => '20m'],
            [
                'meters' => 20,
                'frequency_mhz' => 14.0,
                'is_hf' => true,
                'is_vhf_uhf' => false,
                'is_satellite' => false,
                'allowed_fd' => true,
                'allowed_wfd' => true,
                'sort_order' => 4,
            ]
        );

        $radio = Equipment::factory()->create([
            'type' => 'radio',
            'make' => 'Kenwood',
            'model' => 'TS-590SG',
            'power_output_watts' => 100,
            'owner_user_id' => $user->id,
        ]);
        $radio->bands()->sync([$hfBand->id]);

        $station = Station::factory()->create([
            'event_configuration_id' => $eventConfig->id,
            'radio_equipment_id' => $radio->id,
            'name' => 'PW Test Station',
            'max_power_watts' => $maxPowerWatts,
        ]);

        return compact('user', 'event', 'eventConfig', 'station', 'radio');
    }

    /**
     * Scenario: antenna with incompatible bands triggers warning modal.
     */
    private function seedBandWarningScenario(): array
    {
        $infra = $this->createInfrastructure();

        $vhfBand = Band::firstOrCreate(
            ['name' => '2m'],
            [
                'meters' => 2,
                'frequency_mhz' => 144.0,
                'is_hf' => false,
                'is_vhf_uhf' => true,
                'is_satellite' => false,
                'allowed_fd' => true,
                'allowed_wfd' => true,
                'sort_order' => 8,
            ]
        );

        $antenna = Equipment::factory()->create([
            'type' => 'antenna',
            'make' => 'Diamond',
            'model' => 'X50A',
            'owner_user_id' => $infra['user']->id,
        ]);
        $antenna->bands()->sync([$vhfBand->id]);

        EquipmentEvent::create([
            'equipment_id' => $antenna->id,
            'event_id' => $infra['event']->id,
            'station_id' => null,
            'status' => 'committed',
            'committed_at' => now(),
            'status_changed_at' => now(),
        ]);

        return [
            'user_email' => 'playwright-test@example.com',
            'user_password' => 'playwright-test-password',
            'station_id' => $infra['station']->id,
            'equipment_id' => $antenna->id,
            'equipment_label' => 'Diamond X50A',
        ];
    }

    /**
     * Scenario: amplifier exceeding power limit triggers warning modal.
     */
    private function seedPowerWarningScenario(): array
    {
        $infra = $this->createInfrastructure(maxPowerWatts: 100);

        $amplifier = Equipment::factory()->create([
            'type' => 'amplifier',
            'make' => 'Elecraft',
            'model' => 'KPA1500',
            'power_output_watts' => 1500,
            'owner_user_id' => $infra['user']->id,
        ]);

        EquipmentEvent::create([
            'equipment_id' => $amplifier->id,
            'event_id' => $infra['event']->id,
            'station_id' => null,
            'status' => 'committed',
            'committed_at' => now(),
            'status_changed_at' => now(),
        ]);

        return [
            'user_email' => 'playwright-test@example.com',
            'user_password' => 'playwright-test-password',
            'station_id' => $infra['station']->id,
            'equipment_id' => $amplifier->id,
            'equipment_label' => 'Elecraft KPA1500',
        ];
    }

    /**
     * Scenario: compatible equipment assigns without warning.
     */
    private function seedNoWarningScenario(): array
    {
        $infra = $this->createInfrastructure();

        $accessory = Equipment::factory()->create([
            'type' => 'accessory',
            'make' => 'MFJ',
            'model' => '993B',
            'owner_user_id' => $infra['user']->id,
        ]);

        EquipmentEvent::create([
            'equipment_id' => $accessory->id,
            'event_id' => $infra['event']->id,
            'station_id' => null,
            'status' => 'committed',
            'committed_at' => now(),
            'status_changed_at' => now(),
        ]);

        return [
            'user_email' => 'playwright-test@example.com',
            'user_password' => 'playwright-test-password',
            'station_id' => $infra['station']->id,
            'equipment_id' => $accessory->id,
            'equipment_label' => 'MFJ 993B',
        ];
    }

    /**
     * Scenario: station exists and needs power source updated.
     */
    private function seedStationUpdateScenario(): array
    {
        $infra = $this->createInfrastructure();

        return [
            'user_email' => 'playwright-test@example.com',
            'user_password' => 'playwright-test-password',
            'station_id' => $infra['station']->id,
        ];
    }

    /**
     * Scenario: manager creates shifts, assigns users, confirms check-ins.
     */
    private function seedScheduleManageScenario(): array
    {
        $infra = $this->createInfrastructure();
        $infra['user']->givePermissionTo(
            Permission::firstOrCreate(['name' => 'manage-shifts'])
        );

        // Seed default shift roles
        ShiftRole::seedDefaults($infra['eventConfig']);

        // Create a second user to assign
        $operator = User::factory()->create([
            'email' => 'playwright-operator@example.com',
            'password' => Hash::make('playwright-test-password'),
            'call_sign' => 'PW2OPR',
            'first_name' => 'Operator',
            'last_name' => 'Bob',
        ]);

        return [
            'user_email' => 'playwright-test@example.com',
            'user_password' => 'playwright-test-password',
            'operator_id' => $operator->id,
            'operator_name' => 'Operator Bob',
            'event_config_id' => $infra['eventConfig']->id,
        ];
    }

    /**
     * Scenario: user sees open shifts and can sign up.
     */
    private function seedScheduleSignupScenario(): array
    {
        $infra = $this->createInfrastructure();
        $infra['user']->givePermissionTo(
            Permission::firstOrCreate(['name' => 'manage-shifts'])
        );

        ShiftRole::seedDefaults($infra['eventConfig']);

        $role = ShiftRole::where('event_configuration_id', $infra['eventConfig']->id)
            ->where('name', 'Public Greeter')
            ->first();

        // Create an open shift in the future with capacity 2
        $shift = Shift::create([
            'event_configuration_id' => $infra['eventConfig']->id,
            'shift_role_id' => $role->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
            'capacity' => 2,
            'is_open' => true,
        ]);

        // Create a closed/full shift (capacity 1, already assigned)
        $closedRole = ShiftRole::where('event_configuration_id', $infra['eventConfig']->id)
            ->where('name', 'Event Manager')
            ->first();

        $fullShift = Shift::create([
            'event_configuration_id' => $infra['eventConfig']->id,
            'shift_role_id' => $closedRole->id,
            'start_time' => appNow()->addHours(2),
            'end_time' => appNow()->addHours(4),
            'capacity' => 1,
            'is_open' => false,
        ]);

        $otherUser = User::factory()->create([
            'email' => 'playwright-operator@example.com',
            'password' => Hash::make('playwright-test-password'),
            'call_sign' => 'PW2OPR',
            'first_name' => 'Operator',
            'last_name' => 'Bob',
        ]);

        ShiftAssignment::create([
            'shift_id' => $fullShift->id,
            'user_id' => $otherUser->id,
            'status' => 'scheduled',
            'signup_type' => 'assigned',
        ]);

        return [
            'user_email' => 'playwright-test@example.com',
            'user_password' => 'playwright-test-password',
            'open_shift_id' => $shift->id,
            'full_shift_id' => $fullShift->id,
            'role_name' => 'Public Greeter',
        ];
    }

    /**
     * Scenario: user has an assigned shift they can check in/out of,
     * and a manager can confirm bonus-role check-ins.
     */
    private function seedScheduleCheckinScenario(): array
    {
        $infra = $this->createInfrastructure();
        $infra['user']->givePermissionTo(
            Permission::firstOrCreate(['name' => 'manage-shifts'])
        );

        ShiftRole::seedDefaults($infra['eventConfig']);

        // Create a non-bonus role shift (current time so check-in works)
        $stationCapRole = ShiftRole::where('event_configuration_id', $infra['eventConfig']->id)
            ->where('name', 'Station Captain')
            ->first();

        $currentShift = Shift::create([
            'event_configuration_id' => $infra['eventConfig']->id,
            'shift_role_id' => $stationCapRole->id,
            'start_time' => appNow()->subHour(),
            'end_time' => appNow()->addHours(3),
            'capacity' => 1,
            'is_open' => false,
        ]);

        $assignment = ShiftAssignment::create([
            'shift_id' => $currentShift->id,
            'user_id' => $infra['user']->id,
            'status' => 'scheduled',
            'signup_type' => 'assigned',
        ]);

        // Create a bonus role shift with a second user checked in (for confirmation testing)
        $safetyRole = ShiftRole::where('event_configuration_id', $infra['eventConfig']->id)
            ->where('name', 'Safety Officer')
            ->first();

        $bonusShift = Shift::create([
            'event_configuration_id' => $infra['eventConfig']->id,
            'shift_role_id' => $safetyRole->id,
            'start_time' => appNow()->subHour(),
            'end_time' => appNow()->addHours(3),
            'capacity' => 1,
            'is_open' => false,
        ]);

        $operator = User::factory()->create([
            'email' => 'playwright-operator@example.com',
            'password' => Hash::make('playwright-test-password'),
            'call_sign' => 'PW2OPR',
            'first_name' => 'Operator',
            'last_name' => 'Bob',
        ]);

        $bonusAssignment = ShiftAssignment::create([
            'shift_id' => $bonusShift->id,
            'user_id' => $operator->id,
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'signup_type' => 'assigned',
        ]);

        return [
            'user_email' => 'playwright-test@example.com',
            'user_password' => 'playwright-test-password',
            'assignment_id' => $assignment->id,
            'bonus_assignment_id' => $bonusAssignment->id,
            'operator_name' => 'Operator Bob',
            'event_config_id' => $infra['eventConfig']->id,
        ];
    }

    /**
     * Clean up test data.
     */
    private function cleanup(): array
    {
        $user = User::where('email', 'playwright-test@example.com')->first();

        if ($user) {
            // Clean shift data
            Event::where('name', 'PW Test Field Day')->each(function (Event $event) {
                $config = $event->eventConfiguration;
                if ($config) {
                    ShiftAssignment::whereHas('shift', fn ($q) => $q->where('event_configuration_id', $config->id))->forceDelete();
                    Shift::where('event_configuration_id', $config->id)->forceDelete();
                    ShiftRole::where('event_configuration_id', $config->id)->forceDelete();
                }
            });

            Equipment::where('owner_user_id', $user->id)->each(function (Equipment $eq) {
                $eq->commitments()->delete();
                $eq->bands()->detach();
                $eq->delete();
            });

            Station::whereHas('eventConfiguration.event', function ($q) {
                $q->where('name', 'PW Test Field Day');
            })->delete();

            Event::where('name', 'PW Test Field Day')->each(function (Event $event) {
                $event->eventConfiguration()?->delete();
                $event->delete();
            });

            $user->delete();
        }

        // Also clean the second test user
        User::where('email', 'playwright-operator@example.com')->delete();

        // Restore the most recent non-test event as current
        $lastEvent = Event::where('name', '!=', 'PW Test Field Day')
            ->orderByDesc('id')
            ->first();
        if ($lastEvent) {
            $lastEvent->update(['is_current' => true]);
        }

        return ['cleaned' => true];
    }
}
