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
use App\Models\Station;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;

class SeedPlaywrightData extends Command
{
    protected $signature = 'app:seed-playwright-data {scenario}';

    protected $description = 'Seed test data for Playwright E2E tests and output JSON with IDs';

    public function handle(): int
    {
        $scenario = $this->argument('scenario');

        $result = match ($scenario) {
            'equipment-warning-band' => $this->seedBandWarningScenario(),
            'equipment-warning-power' => $this->seedPowerWarningScenario(),
            'equipment-no-warning' => $this->seedNoWarningScenario(),
            'station-update' => $this->seedStationUpdateScenario(),
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

        $event = Event::factory()->create([
            'event_type_id' => $eventType->id,
            'is_active' => true,
            'name' => 'PW Test Field Day',
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
     * Clean up test data.
     */
    private function cleanup(): array
    {
        $user = User::where('email', 'playwright-test@example.com')->first();

        if ($user) {
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

        return ['cleaned' => true];
    }
}
