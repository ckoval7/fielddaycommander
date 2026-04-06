<?php

namespace Database\Seeders;

use App\Enums\PowerSource;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\GuestbookEntry;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\SafetyChecklistEntry;
use App\Models\SafetyChecklistItem;
use App\Models\Section;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use App\Support\CallsignGenerator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
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

        // 2. System config
        DB::table('system_config')->insert([
            ['key' => 'setup_completed', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'demo_provisioned_at', 'value' => now()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_name', 'value' => 'Field Day Commander — Demo', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 3. Users: 1 admin, 1 event manager, 2 station captains, 10 operators
        $admin = $this->makeUser('W1SA', 'Sam', 'Anderson', 'admin@demo.example', 'System Administrator', 'Extra');
        $manager = $this->makeUser('K4EM', 'Emma', 'Mitchell', 'manager@demo.example', 'Event Manager', 'Extra');
        $captain1 = $this->makeUser('N3SC', 'Scott', 'Campbell', 'captain1@demo.example', 'Station Captain', 'General');
        $captain2 = $this->makeUser('W9SC', 'Sandra', 'Cooper', 'captain2@demo.example', 'Station Captain', 'General');

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

        // 4. Event (in progress: started 2h ago, ends 22h from now)
        $fdType = EventType::where('code', 'FD')->first();
        $event = Event::create([
            'event_type_id' => $fdType->id,
            'name' => 'Field Day 2025',
            'year' => 2025,
            'start_time' => now()->subHours(2),
            'end_time' => now()->addHours(22),
            'setup_allowed_from' => now()->subHours(26),
            'max_setup_hours' => 24,
            'is_active' => true,
            'is_current' => true,
        ]);

        // 5. EventConfiguration (4A class)
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
        ]);

        // Seed checklist items and shift roles
        SafetyChecklistItem::seedDefaults($config);
        ShiftRole::seedDefaults($config);

        // 6. Build stations, operating sessions, contacts
        $this->seedStationsAndContacts($config, [$captain1, $captain2], $operators);

        // 7. Bonuses
        $this->seedBonuses($config, $manager);

        // 8. Safety checklist (~60% complete)
        $this->seedSafetyChecklist($captain1);

        // 9. Guestbook entries
        $this->seedGuestbook($config);

        // 10. Shift schedule
        $this->seedShifts($config, $event, array_merge([$captain1, $captain2], $operators));
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

        $stationDefs = [
            [
                'name' => '20m SSB',
                'band' => '20m',
                'mode' => 'Phone',
                'is_gota' => false,
                'is_vhf_only' => false,
                'captain' => $captain1,
                'operator' => $operators[0],
                'has_active' => true,
                'radio_make' => 'Icom',
                'radio_model' => 'IC-7300',
            ],
            [
                'name' => '40m SSB',
                'band' => '40m',
                'mode' => 'Phone',
                'is_gota' => false,
                'is_vhf_only' => false,
                'captain' => $captain1,
                'operator' => $operators[2],
                'has_active' => true,
                'radio_make' => 'Yaesu',
                'radio_model' => 'FT-991A',
            ],
            [
                'name' => '15m CW',
                'band' => '15m',
                'mode' => 'CW',
                'is_gota' => false,
                'is_vhf_only' => false,
                'captain' => $captain2,
                'operator' => $operators[4],
                'has_active' => false,
                'radio_make' => 'Elecraft',
                'radio_model' => 'K3S',
            ],
            [
                'name' => 'VHF/UHF',
                'band' => '2m',
                'mode' => 'Phone',
                'is_gota' => false,
                'is_vhf_only' => true,
                'captain' => $captain2,
                'operator' => $operators[6],
                'has_active' => false,
                'radio_make' => 'Kenwood',
                'radio_model' => 'TM-V71A',
            ],
            [
                'name' => 'GOTA',
                'band' => '40m',
                'mode' => 'Phone',
                'is_gota' => true,
                'is_vhf_only' => false,
                'captain' => $captain1,
                'operator' => $operators[8],
                'has_active' => true,
                'radio_make' => 'Icom',
                'radio_model' => 'IC-718',
            ],
        ];

        foreach ($stationDefs as $def) {
            $band = Band::where('name', $def['band'])->first();
            $mode = Mode::where('name', $def['mode'])->first();

            // Create equipment (radio)
            $equipment = Equipment::create([
                'owner_user_id' => $def['captain']->id,
                'make' => $def['radio_make'],
                'model' => $def['radio_model'],
                'type' => 'radio',
                'description' => $def['radio_make'].' '.$def['radio_model'].' HF transceiver',
                'power_output_watts' => 100,
            ]);

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

            $historicalCount = random_int(40, 60);
            $this->seedContacts($config, $historicalSession, $historicalCount, $historicalStart, $historicalEnd);

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

                $activeCount = random_int(5, 15);
                $this->seedContacts($config, $activeSession, $activeCount, $activeStart, now());

                $activeSession->update(['qso_count' => $activeCount]);
            }
        }
    }

    private function seedContacts(
        EventConfiguration $config,
        OperatingSession $session,
        int $count,
        Carbon $windowStart,
        Carbon $windowEnd
    ): void {
        $sections = Section::where('is_active', true)->get();
        $fdClasses = ['A', 'B', 'C', 'D', 'E'];
        $windowSeconds = max(1, $windowEnd->diffInSeconds($windowStart));

        for ($i = 0; $i < $count; $i++) {
            $callsign = CallsignGenerator::any();
            $section = $sections->random();
            $fdClass = random_int(1, 5).($fdClasses[array_rand($fdClasses)]);
            $receivedExchange = "{$callsign} {$fdClass} {$section->code}";

            $qsoTime = $windowStart->copy()->addSeconds(random_int(0, $windowSeconds));

            Contact::create([
                'event_configuration_id' => $config->id,
                'operating_session_id' => $session->id,
                'logger_user_id' => $session->operator_user_id,
                'band_id' => $session->band_id,
                'mode_id' => $session->mode_id,
                'qso_time' => $qsoTime,
                'callsign' => $callsign,
                'section_id' => $section->id,
                'received_exchange' => $receivedExchange,
                'power_watts' => 100,
                'is_gota_contact' => $session->is_supervised,
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

    private function seedShifts(EventConfiguration $config, Event $event, array $users): void
    {
        $shiftRole = ShiftRole::where('event_configuration_id', $config->id)->first();

        if (! $shiftRole) {
            return;
        }

        // Create 4 shifts across the event
        $shiftDefs = [
            ['start' => now()->subHours(2), 'end' => now()->addHours(4)],
            ['start' => now()->addHours(4), 'end' => now()->addHours(10)],
            ['start' => now()->addHours(10), 'end' => now()->addHours(16)],
            ['start' => now()->addHours(16), 'end' => now()->addHours(22)],
        ];

        foreach ($shiftDefs as $index => $def) {
            $shift = Shift::create([
                'event_configuration_id' => $config->id,
                'shift_role_id' => $shiftRole->id,
                'start_time' => $def['start'],
                'end_time' => $def['end'],
                'capacity' => 4,
                'is_open' => true,
            ]);

            // Assign 2-3 users per shift
            $assignees = array_slice($users, ($index * 3) % count($users), 3);
            foreach ($assignees as $user) {
                $status = $def['start']->isPast() ? ShiftAssignment::STATUS_CHECKED_IN : ShiftAssignment::STATUS_SCHEDULED;
                ShiftAssignment::create([
                    'shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'status' => $status,
                    'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
                    'checked_in_at' => $status === ShiftAssignment::STATUS_CHECKED_IN ? $def['start'] : null,
                ]);
            }
        }
    }
}
