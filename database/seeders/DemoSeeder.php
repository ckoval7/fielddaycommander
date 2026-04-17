<?php

namespace Database\Seeders;

use App\Enums\PowerSource;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\GuestbookEntry;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Organization;
use App\Models\SafetyChecklistEntry;
use App\Models\SafetyChecklistItem;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use App\Support\CallsignGenerator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    private const ROLE_STATION_CAPTAIN = 'Station Captain';

    public function run(): void
    {
        // Suppress notification-firing observers during seeding to prevent
        // broadcast storms that exceed PHP's max_execution_time when Reverb
        // is unavailable (each failed HTTP round-trip × hundreds of records).
        $silenced = [Contact::class, GuestbookEntry::class, OperatingSession::class, EquipmentEvent::class];
        foreach ($silenced as $model) {
            $model::unsetEventDispatcher();
        }

        try {
            $this->seed();
        } finally {
            $dispatcher = app('events');
            foreach ($silenced as $model) {
                $model::setEventDispatcher($dispatcher);
            }
        }
    }

    private function seed(): void
    {
        // 1. Reference data (skip SystemAdminSeeder — we create our own users)
        $this->call([
            EventTypeSeeder::class,
            BandSeeder::class,
            ModeSeeder::class,
            SectionSeeder::class,
            OperatingClassSeeder::class,
            BonusTypeSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        // 2. Organization (club info)
        $organization = Organization::create([
            'name' => 'Demo Radio Club',
            'callsign' => 'W1FDC',
            'email' => 'info@demoradioclub.example',
            'phone' => '(860) 555-0173',
            'address' => '42 Hilltop Park, Hartford, CT 06103',
            'is_active' => true,
        ]);

        // 3. System config
        Setting::set('setup_completed', 'true');
        Setting::set('demo_provisioned_at', now()->toIso8601String());
        Setting::set('site_name', 'Field Day Commander — Demo');
        Setting::set('timezone', 'America/New_York');
        Setting::set('default_organization_id', $organization->id);

        // 4. Users: 1 admin, 1 event manager, 2 station captains, 10 operators
        $admin = $this->makeUser('W1SA', 'Sam', 'Anderson', 'admin@demo.example', 'System Administrator', 'Extra');
        $manager = $this->makeUser('K4EM', 'Emma', 'Mitchell', 'manager@demo.example', 'Event Manager', 'Extra');
        $captain1 = $this->makeUser('N3SC', 'Scott', 'Campbell', 'captain1@demo.example', self::ROLE_STATION_CAPTAIN, 'General');
        $captain2 = $this->makeUser('W9SC', 'Sandra', 'Cooper', 'captain2@demo.example', self::ROLE_STATION_CAPTAIN, 'General');

        // Mark a captain as CPR/AED trained and one operator as youth
        $captain1->update(['is_cpr_aed_trained' => true]);

        $operatorDefs = [
            ['KD8LKQ', 'John', 'Baker'],    ['W4TRX', 'Maria', 'Torres'],
            ['N5BCF', 'David', 'Wilson'],    ['KA3YJM', 'Lisa', 'Chen'],
            ['WB9TFQ', 'Robert', 'Garcia'],  ['K7HNV', 'Jennifer', 'Smith'],
            ['W3JBR', 'James', 'Brown'],      ['KG5PRM', 'Patricia', 'Davis'],
            ['W2QXB', 'Michael', 'Johnson'], ['N8SWT', 'Nancy', 'White'],
        ];
        $operators = array_map(
            fn ($d) => $this->makeUser($d[0], $d[1], $d[2], strtolower($d[0]).'@demo.example', 'Operator', 'General'),
            $operatorDefs
        );

        // Patricia Davis (index 7) is a youth operator
        $operators[7]->update(['is_youth' => true]);

        // 5. Event (in progress: started 2h ago, ends 22h from now)
        $fdType = EventType::where('code', 'FD')->first();
        $event = Event::create([
            'event_type_id' => $fdType->id,
            'name' => 'Field Day '.now()->year,
            'year' => now()->year,
            'start_time' => now()->subHours(2),
            'end_time' => now()->addHours(22),
            'setup_allowed_from' => now()->subHours(26),
            'max_setup_hours' => 24,
            'is_active' => true,
            'is_current' => true,
        ]);

        // 6. EventConfiguration (4A class)
        $section = Section::where('code', 'CT')->first() ?? Section::first();
        $class4a = OperatingClass::where('code', 'A')->first();
        $config = EventConfiguration::create([
            'event_id' => $event->id,
            'created_by_user_id' => $manager->id,
            'callsign' => 'W1FDC',
            'club_name' => 'Demo Radio Club',
            'section_id' => $section->id,
            'operating_class_id' => $class4a->id,
            'transmitter_count' => 4,
            'has_gota_station' => true,
            'gota_callsign' => 'W1GOT',
            'max_power_watts' => 100,
            'power_multiplier' => '2',
            'uses_commercial_power' => false,
            'uses_generator' => true,
            'uses_battery' => false,
            'uses_solar' => false,
            'uses_wind' => false,
            'uses_water' => false,
            'uses_methane' => false,
            'guestbook_enabled' => true,
            'grid_square' => 'FN31',
            'latitude' => 41.4307,
            'longitude' => -72.8906,
            'city' => 'Hamden',
            'state' => 'CT',
        ]);

        // Seed checklist items and shift roles
        SafetyChecklistItem::seedDefaults($config);
        ShiftRole::seedDefaults($config);

        // 7. Build stations, operating sessions, contacts
        $this->seedStationsAndContacts($config, [$captain1, $captain2], $operators);

        // 7b. Equipment inventory and station assignments
        $this->seedEquipment($config, $organization, $manager, $event, $captain2);

        // 8. Bonuses
        $this->seedBonuses($config, $manager);

        // 9. Safety checklist (~60% complete)
        $this->seedSafetyChecklist($captain1);

        // 10. Guestbook entries
        $this->seedGuestbook($config);

        // 11. Shift schedule
        $this->seedShifts($config, $admin, $manager, $captain1, $captain2, $operators);
    }

    private function makeUser(
        string $callSign,
        string $firstName,
        string $lastName,
        string $email,
        string $role,
        string $licenseClass
    ): User {
        $user = User::create([
            'call_sign' => $callSign,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make('demo-password'),
            'license_class' => $licenseClass,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function seedStationsAndContacts(
        EventConfiguration $config,
        array $captains,
        array $operators
    ): void {
        [$captain1, $captain2] = $captains;

        // 4 HF stations (Alpha–Delta) satisfy the 4A transmitter count.
        // VHF/UHF and GOTA do not count toward that total.
        // Band names each radio supports (matched to real-world specs)
        $stationDefs = [
            [
                'name' => 'Station Alpha',
                'band' => '20m',
                'mode' => 'Phone',
                'is_gota' => false,
                'is_vhf_only' => false,
                'captain' => $captain1,
                'operator' => $operators[0],
                'has_active' => true,
                'radio_make' => 'Icom',
                'radio_model' => 'IC-7300',
                'radio_bands' => ['160m', '80m', '40m', '20m', '15m', '10m', '6m'],
            ],
            [
                'name' => 'Station Bravo',
                'band' => '40m',
                'mode' => 'Phone',
                'is_gota' => false,
                'is_vhf_only' => false,
                'captain' => $captain1,
                'operator' => $operators[2],
                'has_active' => true,
                'radio_make' => 'Yaesu',
                'radio_model' => 'FT-991A',
                'radio_bands' => ['160m', '80m', '40m', '20m', '15m', '10m', '6m', '2m', '70cm'],
            ],
            [
                'name' => 'Station Charlie',
                'band' => '15m',
                'mode' => 'CW',
                'is_gota' => false,
                'is_vhf_only' => false,
                'captain' => $captain2,
                'operator' => $operators[4],
                'has_active' => false,
                'radio_make' => 'Elecraft',
                'radio_model' => 'K3S',
                'radio_bands' => ['160m', '80m', '40m', '20m', '15m', '10m', '6m'],
            ],
            [
                'name' => 'Station Delta',
                'band' => '80m',
                'mode' => 'Phone',
                'is_gota' => false,
                'is_vhf_only' => false,
                'captain' => $captain2,
                'operator' => $operators[6],
                'has_active' => true,
                'radio_make' => 'Kenwood',
                'radio_model' => 'TS-590SG',
                'radio_bands' => ['160m', '80m', '40m', '20m', '15m', '10m', '6m'],
            ],
            [
                'name' => 'VHF/UHF',
                'band' => '2m',
                'mode' => 'Phone',
                'is_gota' => false,
                'is_vhf_only' => true,
                'captain' => $captain2,
                'operator' => $operators[7],
                'has_active' => false,
                'radio_make' => 'Kenwood',
                'radio_model' => 'TM-V71A',
                'radio_bands' => ['2m', '70cm'],
                'historical_count' => [4, 8],
            ],
            [
                'name' => 'GOTA',
                'band' => '40m',
                'mode' => 'Phone',
                'is_gota' => true,
                'is_vhf_only' => false,
                'captain' => $captain1,
                'operator' => $operators[8],
                'has_active' => false,
                'radio_make' => 'Icom',
                'radio_model' => 'IC-718',
                'radio_bands' => ['160m', '80m', '40m', '20m', '15m', '10m'],
                'historical_count' => [5, 10],
                'active_count' => [1, 3],
            ],
        ];

        foreach ($stationDefs as $def) {
            $band = Band::where('name', $def['band'])->first();
            $mode = Mode::where('name', $def['mode'])->first();

            // Create equipment (radio) with supported bands
            $equipment = Equipment::create([
                'owner_user_id' => $def['captain']->id,
                'make' => $def['radio_make'],
                'model' => $def['radio_model'],
                'type' => 'radio',
                'description' => $def['radio_make'].' '.$def['radio_model'].' HF transceiver',
                'power_output_watts' => 100,
            ]);

            if (! empty($def['radio_bands'])) {
                $bandIds = Band::whereIn('name', $def['radio_bands'])->pluck('id');
                $equipment->bands()->attach($bandIds);
            }

            // Create station
            $station = Station::create([
                'event_configuration_id' => $config->id,
                'radio_equipment_id' => $equipment->id,
                'name' => $def['name'],
                'power_source' => PowerSource::Generator,
                'is_gota' => $def['is_gota'],
                'is_vhf_only' => $def['is_vhf_only'],
                'is_satellite' => false,
                'max_power_watts' => 100,
            ]);

            // Historical session (closed): 2h ago → 35min ago
            $historicalStart = now()->subHours(2);
            $historicalEnd = now()->subMinutes(35);
            $historicalSession = OperatingSession::create([
                'station_id' => $station->id,
                'operator_user_id' => $def['operator']->id,
                'band_id' => $band->id,
                'mode_id' => $mode->id,
                'start_time' => $historicalStart,
                'end_time' => $historicalEnd,
                'power_watts' => 100,
                'qso_count' => 0,
                'is_transcription' => false,
                'is_supervised' => $def['is_gota'],
            ]);

            $gotaOperators = null;
            if ($def['is_gota']) {
                $gotaOperators = [
                    ['first_name' => 'Jamie', 'last_name' => 'Rivera', 'callsign' => null],
                    ['first_name' => 'Morgan', 'last_name' => 'Chen', 'callsign' => null],
                    ['first_name' => 'Taylor', 'last_name' => 'Brooks', 'callsign' => null],
                ];
            }

            [$histMin, $histMax] = $def['historical_count'] ?? [20, 30];
            $historicalCount = random_int($histMin, $histMax);
            $this->seedContacts($config, $historicalSession, $historicalCount, $historicalStart, $historicalEnd, $gotaOperators);

            $historicalSession->update(['qso_count' => $historicalCount]);

            // Active session (open): started 25min ago, no end_time
            if ($def['has_active']) {
                $activeStart = now()->subMinutes(25);
                $activeSession = OperatingSession::create([
                    'station_id' => $station->id,
                    'operator_user_id' => $def['operator']->id,
                    'band_id' => $band->id,
                    'mode_id' => $mode->id,
                    'start_time' => $activeStart,
                    'end_time' => null,
                    'power_watts' => 100,
                    'qso_count' => 0,
                    'is_transcription' => false,
                    'is_supervised' => $def['is_gota'],
                ]);

                [$actMin, $actMax] = $def['active_count'] ?? [3, 8];
                $activeCount = random_int($actMin, $actMax);
                $this->seedContacts($config, $activeSession, $activeCount, $activeStart, now(), $gotaOperators);

                $activeSession->update(['qso_count' => $activeCount]);
            }
        }
    }

    private function seedEquipment(
        EventConfiguration $config,
        Organization $organization,
        User $manager,
        Event $event,
        User $captain2
    ): void {
        $stations = Station::where('event_configuration_id', $config->id)
            ->with('primaryRadio')
            ->get()
            ->keyBy('name');

        // Reassign most station radios to club ownership (heavy-club profile)
        foreach (['Station Alpha', 'Station Bravo', 'VHF/UHF', 'GOTA'] as $stationName) {
            $stations[$stationName]->primaryRadio->update([
                'owner_user_id' => null,
                'owner_organization_id' => $organization->id,
            ]);
        }

        // Per-station equipment loadouts (all club-owned unless 'owner' => 'user')
        $loadouts = [
            'Station Alpha' => [
                ['type' => 'antenna', 'make' => 'MFJ', 'model' => '1778 G5RV', 'description' => 'G5RV wire antenna', 'bands' => ['40m', '20m', '15m', '10m']],
                ['type' => 'power_supply', 'make' => 'Astron', 'model' => 'RS-35M', 'description' => '35A linear power supply'],
                ['type' => 'accessory', 'make' => 'Heil', 'model' => 'Pro Set Plus', 'description' => 'Boom headset'],
                ['type' => 'accessory', 'make' => 'Heil', 'model' => 'HC-6', 'description' => 'Desk mic element'],
            ],
            'Station Bravo' => [
                ['type' => 'antenna', 'make' => 'Homebrew', 'model' => 'Inverted-V Dipole', 'description' => 'Inverted-V dipole cut for 40m', 'bands' => ['40m']],
                ['type' => 'power_supply', 'make' => 'Samlex', 'model' => 'SEC-1235M', 'description' => '30A switching power supply'],
                ['type' => 'accessory', 'make' => 'Yaesu', 'model' => 'MD-100', 'description' => 'Desktop microphone'],
            ],
            'Station Charlie' => [
                ['type' => 'antenna', 'make' => 'SteppIR', 'model' => 'DB18E', 'description' => '3-element Yagi antenna', 'bands' => ['20m', '15m', '10m']],
                ['type' => 'power_supply', 'make' => 'Astron', 'model' => 'RS-20M', 'description' => '20A linear power supply with meters'],
                ['type' => 'accessory', 'make' => 'Begali', 'model' => 'Sculpture', 'description' => 'CW paddle', 'owner' => 'user'],
                ['type' => 'amplifier', 'make' => 'Elecraft', 'model' => 'KPA500', 'description' => '500W solid-state amplifier', 'power_output_watts' => 500],
            ],
            'Station Delta' => [
                ['type' => 'antenna', 'make' => 'Alpha Delta', 'model' => 'DX-B', 'description' => 'Parallel dipole antenna', 'bands' => ['80m', '40m', '20m', '15m', '10m']],
                ['type' => 'power_supply', 'make' => 'Samlex', 'model' => 'SEC-1223M', 'description' => '23A switching power supply'],
                ['type' => 'accessory', 'make' => 'Kenwood', 'model' => 'MC-43S', 'description' => 'Hand microphone'],
                ['type' => 'accessory', 'make' => 'Heil', 'model' => 'Pro Set', 'description' => 'Boom headset'],
            ],
            'VHF/UHF' => [
                ['type' => 'antenna', 'make' => 'Diamond', 'model' => 'X50A', 'description' => 'Dual-band VHF/UHF vertical antenna', 'bands' => ['2m', '70cm']],
                ['type' => 'power_supply', 'make' => 'Powerwerx', 'model' => 'SS-30DV', 'description' => '30A switching power supply with Anderson Powerpoles'],
                ['type' => 'accessory', 'make' => 'Kenwood', 'model' => 'MC-59', 'description' => 'DTMF hand microphone'],
            ],
            'GOTA' => [
                ['type' => 'antenna', 'make' => 'MFJ', 'model' => '1982MP', 'description' => 'End-fed half-wave wire antenna', 'bands' => ['80m', '40m', '20m', '15m', '10m']],
                ['type' => 'power_supply', 'make' => 'Astron', 'model' => 'RS-20M', 'description' => '20A linear power supply with meters'],
                ['type' => 'accessory', 'make' => 'Icom', 'model' => 'HM-36', 'description' => 'Hand microphone'],
                ['type' => 'accessory', 'make' => 'MFJ', 'model' => '557', 'description' => 'Straight key for CW demos'],
            ],
        ];

        foreach ($loadouts as $stationName => $items) {
            $station = $stations[$stationName];

            // Create EquipmentEvent for the station's primary radio
            EquipmentEvent::create([
                'equipment_id' => $station->radio_equipment_id,
                'event_id' => $event->id,
                'station_id' => $station->id,
                'assigned_by_user_id' => $manager->id,
                'status' => 'delivered',
                'committed_at' => $event->start_time,
                'status_changed_at' => $event->start_time,
            ]);

            foreach ($items as $item) {
                $isUserOwned = ($item['owner'] ?? 'club') === 'user';

                $equipment = Equipment::create([
                    'owner_organization_id' => $isUserOwned ? null : $organization->id,
                    'owner_user_id' => $isUserOwned ? $captain2->id : null,
                    'make' => $item['make'],
                    'model' => $item['model'],
                    'type' => $item['type'],
                    'description' => $item['description'],
                    'power_output_watts' => $item['power_output_watts'] ?? null,
                ]);

                if (! empty($item['bands'])) {
                    $bandIds = Band::whereIn('name', $item['bands'])->pluck('id');
                    $equipment->bands()->attach($bandIds);
                }

                EquipmentEvent::create([
                    'equipment_id' => $equipment->id,
                    'event_id' => $event->id,
                    'station_id' => $station->id,
                    'assigned_by_user_id' => $manager->id,
                    'status' => 'delivered',
                    'committed_at' => $event->start_time,
                    'status_changed_at' => $event->start_time,
                ]);
            }
        }

        // Spare equipment: committed to event but not assigned to any station
        $spares = [
            ['type' => 'power_supply', 'make' => 'Samlex', 'model' => 'SEC-1235M', 'description' => 'Backup 30A switching power supply'],
            ['type' => 'antenna', 'make' => 'Comet', 'model' => 'GP-3', 'description' => 'Dual-band VHF/UHF base antenna', 'bands' => ['2m', '70cm']],
        ];

        foreach ($spares as $item) {
            $equipment = Equipment::create([
                'owner_organization_id' => $organization->id,
                'make' => $item['make'],
                'model' => $item['model'],
                'type' => $item['type'],
                'description' => $item['description'],
            ]);

            if (! empty($item['bands'])) {
                $bandIds = Band::whereIn('name', $item['bands'])->pluck('id');
                $equipment->bands()->attach($bandIds);
            }

            EquipmentEvent::create([
                'equipment_id' => $equipment->id,
                'event_id' => $event->id,
                'station_id' => null,
                'assigned_by_user_id' => $manager->id,
                'status' => 'committed',
                'committed_at' => $event->start_time,
                'status_changed_at' => $event->start_time,
            ]);
        }
    }

    /**
     * @param  array<int, array{first_name: string, last_name: string, callsign: string|null}>|null  $gotaOperators
     */
    private function seedContacts(
        EventConfiguration $config,
        OperatingSession $session,
        int $count,
        Carbon $windowStart,
        Carbon $windowEnd,
        ?array $gotaOperators = null,
    ): void {
        $allSections = Section::where('is_active', true)->get()->keyBy('code');
        $canadianSections = $allSections->filter(fn (Section $s) => $s->country === 'CA');
        $windowSeconds = max(1, $windowEnd->diffInSeconds($windowStart));

        // Weighted class pool: A most common, F very rare
        $classPool = array_merge(
            array_fill(0, 50, 'A'),
            array_fill(0, 20, 'B'),
            array_fill(0, 15, 'C'),
            array_fill(0, 10, 'D'),
            array_fill(0, 4, 'E'),
            array_fill(0, 1, 'F'),
        );

        // Build a weighted section pool. Nearby/populous sections appear more often;
        // rarer/distant ones appear once. This mimics a real early-event QSO log where
        // you've worked the easy locals heavily but haven't hit every section yet.
        $nearbyPool = [
            'CT', 'CT', 'CT',
            'EMA', 'EMA', 'WMA', 'WMA',
            'NH', 'VT', 'ME',
            'NNJ', 'NNJ', 'SNJ',
            'ENY', 'ENY', 'WNY',
            'EPA', 'WPA', 'MDC', 'DE',
            'RI', 'RI',
        ];

        $midPool = [
            'VA', 'VA', 'SVA', 'WCF', 'NFL',
            'OH', 'OH', 'IN', 'MI', 'MI',
            'IL', 'WI', 'MN',
            'NC', 'SC', 'GA', 'TN',
            'CO', 'AZ', 'UT',
            'OR', 'WWA', 'EWA',
            'LAX', 'SCV', 'SDG',
        ];

        // 65% nearby, 35% mid-range — creates a realistic patchy coverage map
        $weightedPool = array_merge(
            array_fill(0, 65, 'nearby'),
            array_fill(0, 35, 'mid'),
        );

        for ($i = 0; $i < $count; $i++) {
            // Match callsign nationality to section: US callsigns get US sections,
            // Canadian callsigns get Canadian sections.
            $isCanadian = random_int(1, 100) > 85;

            if ($isCanadian) {
                $callsign = CallsignGenerator::canada();
                $section = $canadianSections->random();
            } else {
                $callsign = CallsignGenerator::us();
                $bucket = $weightedPool[array_rand($weightedPool)];
                $pool = $bucket === 'nearby' ? $nearbyPool : $midPool;
                $code = $pool[array_rand($pool)];
                $section = $allSections->get($code) ?? $allSections->random();
            }

            $fdClassLetter = $classPool[array_rand($classPool)];
            $transmitterCount = match ($fdClassLetter) {
                'A' => random_int(1, 20),
                'B' => random_int(1, 2),
                'F' => random_int(2, 10),
                default => 1,
            };
            $fdClass = $transmitterCount.$fdClassLetter;

            $qsoTime = $windowStart->copy()->addSeconds(random_int(0, $windowSeconds));

            $gotaOp = $gotaOperators ? $gotaOperators[array_rand($gotaOperators)] : null;

            Contact::create([
                'event_configuration_id' => $config->id,
                'operating_session_id' => $session->id,
                'logger_user_id' => $session->operator_user_id,
                'band_id' => $session->band_id,
                'mode_id' => $session->mode_id,
                'qso_time' => $qsoTime,
                'callsign' => $callsign,
                'section_id' => $section->id,
                'exchange_class' => $fdClass,
                'power_watts' => 100,
                'is_gota_contact' => $session->is_supervised,
                'gota_operator_first_name' => $gotaOp['first_name'] ?? null,
                'gota_operator_last_name' => $gotaOp['last_name'] ?? null,
                'gota_operator_callsign' => $gotaOp['callsign'] ?? null,
                'is_natural_power' => false,
                'is_satellite' => false,
                'points' => 2,
                'is_duplicate' => false,
            ]);
        }
    }

    private function seedBonuses(EventConfiguration $config, User $manager): void
    {
        $verifiedCodes = ['emergency_power', 'public_location', 'w1aw_bulletin'];

        foreach ($verifiedCodes as $code) {
            $bonusType = BonusType::where('code', $code)->first();
            if (! $bonusType) {
                continue;
            }

            EventBonus::create([
                'event_configuration_id' => $config->id,
                'bonus_type_id' => $bonusType->id,
                'claimed_by_user_id' => $manager->id,
                'quantity' => 1,
                'calculated_points' => $bonusType->base_points,
                'is_verified' => true,
                'verified_by_user_id' => $manager->id,
                'verified_at' => now()->subMinutes(30),
            ]);
        }

        // One pending bonus
        $mediaBonusType = BonusType::where('code', 'media_publicity')->first();
        if ($mediaBonusType) {
            EventBonus::create([
                'event_configuration_id' => $config->id,
                'bonus_type_id' => $mediaBonusType->id,
                'claimed_by_user_id' => $manager->id,
                'quantity' => 1,
                'calculated_points' => $mediaBonusType->base_points,
                'notes' => 'Local newspaper article submitted for review',
                'is_verified' => false,
            ]);
        }
    }

    private function seedSafetyChecklist(User $captain): void
    {
        $items = SafetyChecklistItem::all();

        if ($items->isEmpty()) {
            return;
        }

        $toComplete = (int) ceil($items->count() * 0.6);
        $selectedItems = $items->shuffle()->take($toComplete);

        foreach ($selectedItems as $item) {
            SafetyChecklistEntry::where('safety_checklist_item_id', $item->id)
                ->update([
                    'is_completed' => true,
                    'completed_by_user_id' => $captain->id,
                    'completed_at' => now()->subMinutes(random_int(10, 90)),
                ]);
        }
    }

    private function seedGuestbook(EventConfiguration $config): void
    {
        $entries = [
            // Licensed hams visiting in person
            [
                'callsign' => 'W1ABC', 'first_name' => 'Alice', 'last_name' => 'Brown',
                'email' => null, 'comments' => 'Great setup! Running 4A is impressive. 73!',
                'presence_type' => 'in_person', 'visitor_category' => 'ham_club',
            ],
            [
                'callsign' => 'VE3MNO', 'first_name' => 'Frank', 'last_name' => 'Davis',
                'email' => 'frank@example.com', 'comments' => 'Drove down from across the border to check it out. Beautiful location.',
                'presence_type' => 'in_person', 'visitor_category' => 'ham_club',
            ],
            [
                'callsign' => 'K8JKL', 'first_name' => 'Eve', 'last_name' => 'Wilson',
                'email' => null, 'comments' => 'I let my license lapse years ago — this is making me want to get back into it!',
                'presence_type' => 'in_person', 'visitor_category' => 'ham_club',
            ],
            // ARES/RACES
            [
                'callsign' => 'N4DEF', 'first_name' => 'Carol', 'last_name' => 'Jones',
                'email' => 'carol.jones@countyares.org', 'comments' => 'Stopping by on behalf of the county ARES group. Excellent turnout.',
                'presence_type' => 'in_person', 'visitor_category' => 'ares_races',
            ],
            // General public — no callsign
            [
                'callsign' => null, 'first_name' => 'David', 'last_name' => 'Miller',
                'email' => null, 'comments' => 'My son dragged me here and I had no idea what any of this was. Now I want to get my license!',
                'presence_type' => 'in_person', 'visitor_category' => 'general_public',
            ],
            [
                'callsign' => null, 'first_name' => 'Grace', 'last_name' => 'Taylor',
                'email' => 'grace@example.com', 'comments' => 'Thank you for being out here. Love seeing the community come together.',
                'presence_type' => 'in_person', 'visitor_category' => 'general_public',
            ],
            // Youth group
            [
                'callsign' => null, 'first_name' => 'Troop 214', 'last_name' => '',
                'email' => null, 'comments' => 'Our scout troop visited and the operators were so patient answering our questions. The kids loved the CW demo!',
                'presence_type' => 'in_person', 'visitor_category' => 'youth',
            ],
            // Online visitor
            [
                'callsign' => 'WA3GHI', 'first_name' => 'James', 'last_name' => 'Rodriguez',
                'email' => 'ja3ghi@example.com', 'comments' => 'Following along on the dashboard from home. Looking good on 20m!',
                'presence_type' => 'online', 'visitor_category' => 'ham_club',
            ],
        ];

        foreach ($entries as $entry) {
            GuestbookEntry::create([
                'event_configuration_id' => $config->id,
                'callsign' => $entry['callsign'],
                'first_name' => $entry['first_name'],
                'last_name' => $entry['last_name'],
                'email' => $entry['email'],
                'comments' => $entry['comments'],
                'presence_type' => $entry['presence_type'],
                'visitor_category' => $entry['visitor_category'],
                'is_verified' => true,
                'verified_at' => now()->subMinutes(random_int(5, 120)),
            ]);
        }
    }

    private function seedShifts(
        EventConfiguration $config,
        User $admin,
        User $manager,
        User $captain1,
        User $captain2,
        array $operators
    ): void {
        $roles = ShiftRole::where('event_configuration_id', $config->id)
            ->get()
            ->keyBy('name');

        // ── Operator shifts: 2-hour blocks, 5 slots overlapping now ─────────
        // We show two past slots, the current slot, and two upcoming slots.
        // 4-5 operators per slot covers the 4–5 active stations.
        $operatorSlots = [
            ['start' => now()->subHours(4), 'end' => now()->subHours(2), 'ops' => [0, 1, 2, 3]],
            ['start' => now()->subHours(2), 'end' => now(),              'ops' => [4, 5, 6, 7]],
            ['start' => now(),              'end' => now()->addHours(2), 'ops' => [8, 9, 0, 1]],
            ['start' => now()->addHours(2), 'end' => now()->addHours(4), 'ops' => [2, 3, 4, 5]],
            ['start' => now()->addHours(4), 'end' => now()->addHours(6), 'ops' => [6, 7, 8, 9]],
            ['start' => now()->addHours(6), 'end' => now()->addHours(8), 'ops' => [0, 2, 4, 6]],
        ];

        if ($roles->has('Operator')) {
            foreach ($operatorSlots as $slot) {
                $shift = $this->makeShift($config, $roles['Operator'], $slot['start'], $slot['end'], 5);
                foreach ($slot['ops'] as $i) {
                    $this->assign($shift, $operators[$i]);
                }
            }
        }

        // ── Station Captain: one long block each, covering the event ─────────
        if ($roles->has(self::ROLE_STATION_CAPTAIN)) {
            $cap1Shift = $this->makeShift($config, $roles[self::ROLE_STATION_CAPTAIN], now()->subHours(2), now()->addHours(10), 2);
            $this->assign($cap1Shift, $captain1);

            $cap2Shift = $this->makeShift($config, $roles[self::ROLE_STATION_CAPTAIN], now()->addHours(10), now()->addHours(22), 2);
            $this->assign($cap2Shift, $captain2);
        }

        // ── Event Manager: full event ─────────────────────────────────────────
        if ($roles->has('Event Manager')) {
            $mgr = $this->makeShift($config, $roles['Event Manager'], now()->subHours(2), now()->addHours(22), 1);
            $this->assign($mgr, $manager);
        }

        // ── Safety Officer: two people, 12-hour handoff ────────────────────────
        if ($roles->has('Safety Officer')) {
            $so1 = $this->makeShift($config, $roles['Safety Officer'], now()->subHours(2), now()->addHours(10), 1);
            $this->assign($so1, $captain1);

            $so2 = $this->makeShift($config, $roles['Safety Officer'], now()->addHours(10), now()->addHours(22), 1);
            $this->assign($so2, $admin);
        }

        // ── GOTA Coach: daytime coverage ──────────────────────────────────────
        if ($roles->has('GOTA Coach')) {
            $gc1 = $this->makeShift($config, $roles['GOTA Coach'], now()->subHours(2), now()->addHours(6), 1);
            $this->assign($gc1, $operators[3]);

            $gc2 = $this->makeShift($config, $roles['GOTA Coach'], now()->addHours(6), now()->addHours(14), 1);
            $this->assign($gc2, $operators[7]);
        }

        // ── Public Greeter: morning/afternoon ────────────────────────────────
        if ($roles->has('Public Greeter')) {
            $pg = $this->makeShift($config, $roles['Public Greeter'], now(), now()->addHours(8), 2);
            $this->assign($pg, $operators[5]);
            $this->assign($pg, $operators[9]);
        }

        // ── Site Responsibilities: one person, full event ─────────────────────
        if ($roles->has('Site Responsibilities')) {
            $site = $this->makeShift($config, $roles['Site Responsibilities'], now()->subHours(2), now()->addHours(22), 1);
            $this->assign($site, $captain2);
        }

        // ── Message Handler: one shift ─────────────────────────────────────────
        if ($roles->has('Message Handler')) {
            $msg = $this->makeShift($config, $roles['Message Handler'], now()->addHours(2), now()->addHours(10), 1);
            $this->assign($msg, $operators[1]);
        }
    }

    private function makeShift(
        EventConfiguration $config,
        ShiftRole $role,
        Carbon $start,
        Carbon $end,
        int $capacity
    ): Shift {
        return Shift::create([
            'event_configuration_id' => $config->id,
            'shift_role_id' => $role->id,
            'start_time' => $start,
            'end_time' => $end,
            'capacity' => $capacity,
            'is_open' => true,
        ]);
    }

    private function assign(Shift $shift, User $user): void
    {
        $isPast = $shift->start_time->isPast();
        ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => $isPast ? ShiftAssignment::STATUS_CHECKED_IN : ShiftAssignment::STATUS_SCHEDULED,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
            'checked_in_at' => $isPast ? $shift->start_time : null,
        ]);
    }
}
