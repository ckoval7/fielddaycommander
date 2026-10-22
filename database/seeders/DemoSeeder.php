<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Seeders;

use App\Enums\MessageFormat;
use App\Enums\MessagePrecedence;
use App\Enums\MessageRole;
use App\Enums\NotificationCategory;
use App\Enums\PowerSource;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\BulletinScheduleEntry;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\GuestbookEntry;
use App\Models\Message;
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
use App\Notifications\InAppNotification;
use App\Scoring\DomainEvents\MessageChanged;
use App\Support\CallsignGenerator;
use App\Support\CanadianSectionPicker;
use App\Support\TransmitterCountGenerator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    private const ROLE_STATION_CAPTAIN = 'Station Captain';

    /**
     * Operator indexes for the two HF crews that alternate in 2-hour blocks.
     * Each crew member's position maps to an HF station (Alpha–Delta).
     *
     * @var array<int, array<int, int>>
     */
    private const HF_OPERATOR_CREWS = [[0, 1, 2, 3], [4, 5, 6, 7]];

    /**
     * Container key carrying the event type code the sandbox is built around.
     *
     * The seeder is invoked through `db:seed`, which accepts no custom
     * arguments, so the caller stashes the choice in the container instead.
     */
    public const EVENT_TYPE_KEY = 'demo.seed.event_type';

    /**
     * Season a Winter Field Day sandbox is seeded for.
     *
     * WFD runs in late January, so for most of the calendar year the event
     * visitors are preparing for is next year's. Pinned rather than derived so
     * the sandbox only moves to a season once its ruleset exists.
     */
    public const WINTER_FIELD_DAY_YEAR = 2027;

    /**
     * Event type code the sandbox is seeded for: 'FD' or 'WFD'.
     *
     * Set by {@see forEventType()} before the seeder runs. Defaults to ARRL
     * Field Day so existing callers (and `db:seed` by hand) are unaffected.
     */
    private string $eventTypeCode = 'FD';

    /**
     * Choose which event the sandbox is built around.
     *
     * Unknown codes fall back to FD rather than throwing — the controller
     * validates the input, and a bad value should not leave a visitor with an
     * unseeded database.
     */
    public function forEventType(string $code): static
    {
        $this->eventTypeCode = $code === 'WFD' ? 'WFD' : 'FD';

        return $this;
    }

    private function isWinter(): bool
    {
        return $this->eventTypeCode === 'WFD';
    }

    public function run(): void
    {
        // Picked up when the seeder is dispatched through `db:seed`, which has
        // no way to pass the choice in directly. Harmless when unbound.
        if (app()->bound(self::EVENT_TYPE_KEY)) {
            $this->forEventType((string) app(self::EVENT_TYPE_KEY));
        }

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
        $this->backdate($organization, now()->subYears(6)->subDays(random_int(0, 300)));

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

        // 5. Event (in progress: started on the hour, 2–3 hours ago)
        //
        // WFD runs a 30-hour period against Field Day's 27, but the demo keeps
        // the same "2 hours in" framing for both so the dashboard, shift
        // schedule and contact history line up regardless of which event the
        // visitor picked. Starting on the hour keeps the schedule on clean
        // boundaries instead of the minute the visitor happened to click.
        $eventStart = now()->startOfHour()->subHours(2);
        $eventType = EventType::where('code', $this->eventTypeCode)->first();
        $eventYear = $this->isWinter() ? self::WINTER_FIELD_DAY_YEAR : now()->year;
        $event = Event::create([
            'event_type_id' => $eventType->id,
            'name' => $eventType->name.' '.$eventYear,
            'year' => $eventYear,
            'start_time' => $eventStart,
            'end_time' => $eventStart->copy()->addHours(24),
            'setup_allowed_from' => $eventStart->copy()->subHours(24),
            'max_setup_hours' => 24,
            'is_active' => true,
            'is_current' => true,
        ]);

        // 6. EventConfiguration
        //
        // FD runs 4A with a GOTA station and the 2x low-power multiplier.
        // WFD has neither: the WFDA rules define no GOTA station and no power
        // multiplier (all entrants are capped at 100 W), so the demo entry is
        // 4O — four transmitters, Outdoor — with solar added to the generator
        // so the "operate 100% on alternative power" objective reads true.
        $section = Section::where('code', 'CT')->first() ?? Section::first();
        $operatingClass = OperatingClass::where('event_type_id', $event->event_type_id)
            ->where('code', $this->isWinter() ? 'O' : 'A')
            ->first();

        $config = EventConfiguration::create([
            'event_id' => $event->id,
            'created_by_user_id' => $manager->id,
            'callsign' => 'W1FDC',
            'club_name' => 'Demo Radio Club',
            'section_id' => $section->id,
            'operating_class_id' => $operatingClass->id,
            'transmitter_count' => 4,
            'has_gota_station' => ! $this->isWinter(),
            'gota_callsign' => $this->isWinter() ? null : 'W1GOT',
            'max_power_watts' => 100,
            'power_multiplier' => $this->isWinter() ? '1' : '2',
            'uses_commercial_power' => false,
            'uses_generator' => true,
            'uses_battery' => $this->isWinter(),
            'uses_solar' => $this->isWinter(),
            'uses_wind' => false,
            'uses_water' => false,
            'uses_methane' => false,
            'guestbook_enabled' => true,
            'grid_square' => 'FN31',
            'latitude' => 41.4307,
            'longitude' => -72.8906,
            'city' => 'Hamden',
            'state' => 'CT',
            ...$this->publicVisitorDetails(),
        ]);

        // Planning starts weeks ahead of the operating weekend.
        $plannedAt = $eventStart->copy()->subDays(random_int(35, 60))->setTime(19, random_int(0, 59));
        $this->backdate($event, $plannedAt);
        $this->backdate($config, $plannedAt->copy()->addMinutes(random_int(10, 40)));

        // Seed checklist items and shift roles.
        //
        // Both helpers key off the operating class letter. The WFD classes
        // (H/I/O/M) are absent from those FD-oriented eligibility lists, so a
        // WFD sandbox would otherwise come up with an empty schedule. Seed the
        // roles that carry over to Winter Field Day explicitly instead. The
        // safety checklist is deliberately left empty for WFD classes — that
        // checklist backs an ARRL bonus that WFD does not have.
        SafetyChecklistItem::seedDefaults($config);

        if ($this->isWinter()) {
            $this->seedWinterShiftRoles($config);
        } else {
            ShiftRole::seedDefaults($config);
        }

        // 7. Shift schedule, then the stations, operating sessions and
        // contacts that follow the operator rotation it lays out.
        $operatorShifts = $this->seedShifts($config, $event, $admin, $manager, $captain1, $captain2, $operators);
        $this->seedStationsAndContacts($config, $event, [$captain1, $captain2], $operatorShifts, $operators);

        // 7b. Equipment inventory and station assignments
        $this->seedEquipment($config, $organization, $manager, $event, $captain2);

        // 8. Bonuses
        $this->seedBonuses($config, $manager, $event);

        // 8b. Message traffic and the bulletin sked
        $this->seedMessages($config, $event, $admin);
        $this->seedBulletinSchedule($event, $manager, $plannedAt);

        // 9. Safety checklist (~60% complete)
        $this->seedSafetyChecklist($captain1, $event);

        // 10. Guestbook entries
        $this->seedGuestbook($config, $event, $manager);

        // 11. Notification feed history, replayed from everything above
        $this->seedNotificationHistory($config);
    }

    /**
     * What the club tells the public about visiting, shown on the public
     * landing page.
     *
     * The demo event starts whenever the visitor arrives, so visiting hours
     * are written without clock times rather than against a fixed schedule.
     *
     * @return array<string, mixed>
     */
    private function publicVisitorDetails(): array
    {
        $description = $this->isWinter()
            ? "The Demo Radio Club is on the air for Winter Field Day, running four stations outdoors on solar and battery power to practice emergency communications in cold weather.\n\nStop by to see how it works, warm up by the heater, and ask any questions you like. No license or experience needed."
            : "The Demo Radio Club is on the air for ARRL Field Day, running four HF stations and a VHF station on generator power to practice emergency communications.\n\nCome see how it works, ask the operators anything, and make a contact yourself at our Get On The Air station. No license or experience needed. Families and scout troops are welcome.";

        $visitingHours = $this->isWinter()
            ? "Drop in any time while we're on the air. The visitor table is staffed from late morning until dark each day. Overnight the stations keep running, but it's quiet and cold."
            : "Drop in any time while we're on the air. The visitor table and GOTA station are staffed from noon until 8 PM each day. Overnight the stations keep running with a smaller crew.";

        return [
            'tagline' => $this->isWinter() ? 'On the air all weekend, rain, snow or shine' : 'Amateur radio in action, all weekend long',
            'talk_in_frequency' => '146.520 MHz simplex',
            'is_open_to_public' => true,
            'public_description' => $description,
            'public_directions' => "Hilltop Park pavilion, off Route 10 in Hamden. Follow the antenna masts past the ball fields and park in the upper lot by the orange cones.\n\nThe pavilion and the paths to it are wheelchair accessible.",
            'public_visiting_hours' => $visitingHours,
            'public_contact_email' => 'info@demoradioclub.example',
            'public_contact_phone' => '(860) 555-0173',
            'public_website_url' => 'https://demoradioclub.example',
        ];
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

        // Members joined the club over the past few years, not today.
        $joinedAt = now()->subDays(random_int(60, 1500))->setTime(random_int(8, 21), random_int(0, 59));
        $this->backdate($user, $joinedAt, [
            'email_verified_at' => $joinedAt->copy()->addMinutes(random_int(2, 90)),
        ]);

        return $user;
    }

    /**
     * Stamp a seeded record with when it would really have been created.
     *
     * @param  array<string, mixed>  $extra
     */
    private function backdate(Model $model, Carbon $createdAt, array $extra = []): void
    {
        $model->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            ...$extra,
        ])->saveQuietly();
    }

    /**
     * The later of two times, as a fresh instance so callers can adjust it
     * without moving a model's own timestamp.
     */
    private function later(Carbon $first, Carbon $second): Carbon
    {
        return ($first->gt($second) ? $first : $second)->copy();
    }

    /**
     * The earlier of two times, as a fresh instance.
     */
    private function earlier(Carbon $first, Carbon $second): Carbon
    {
        return ($first->lt($second) ? $first : $second)->copy();
    }

    /**
     * @param  array<int, User>  $captains
     * @param  array<int, Shift>  $operatorShifts
     * @param  array<int, User>  $operators
     */
    private function seedStationsAndContacts(
        EventConfiguration $config,
        Event $event,
        array $captains,
        array $operatorShifts,
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
                'radio_make' => 'Kenwood',
                'radio_model' => 'TM-V71A',
                'radio_bands' => ['2m', '70cm'],
                'contact_count' => [4, 8],
            ],
            [
                'name' => 'GOTA',
                'band' => '40m',
                'mode' => 'Phone',
                'is_gota' => true,
                'is_vhf_only' => false,
                'captain' => $captain1,
                'radio_make' => 'Icom',
                'radio_model' => 'IC-718',
                'radio_bands' => ['160m', '80m', '40m', '20m', '15m', '10m'],
                'contact_count' => [5, 10],
            ],
        ];

        // WFD has no Get On The Air station — the WFDA rules define none — so
        // the GOTA entry is dropped entirely for a Winter Field Day sandbox.
        if ($this->isWinter()) {
            $stationDefs = array_values(array_filter(
                $stationDefs,
                fn (array $def): bool => ! $def['is_gota'],
            ));
        }

        $hfStations = [];

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

            if (! $def['is_gota'] && ! $def['is_vhf_only']) {
                $hfStations[] = ['station' => $station, 'band' => $band, 'mode' => $mode, 'has_active' => $def['has_active']];

                continue;
            }

            // VHF and GOTA each ran one short session early in the event. The
            // VHF operator is free until greeting starts; GOTA is run by the
            // on-duty coach with visitors at the mic.
            $operator = $def['is_gota'] ? $operators[8] : $operators[9];
            $start = $event->start_time->copy()->addMinutes(random_int(15, 35));
            $end = $this->earlier($start->copy()->addMinutes(random_int(55, 80)), now()->subMinutes(5));
            $gotaOperators = $def['is_gota'] ? [
                ['first_name' => 'Jamie', 'last_name' => 'Rivera', 'callsign' => null],
                ['first_name' => 'Morgan', 'last_name' => 'Chen', 'callsign' => null],
                ['first_name' => 'Taylor', 'last_name' => 'Brooks', 'callsign' => null],
            ] : null;

            [$countMin, $countMax] = $def['contact_count'];
            $this->seedSession($config, $station, $operator, $band, $mode, $start, $end, random_int($countMin, $countMax), $gotaOperators);
        }

        $this->seedHfSessions($config, $hfStations, $operatorShifts, $operators);
    }

    /**
     * Staff the HF stations from the operator rotation.
     *
     * Each crew member takes the station matching their crew position. A
     * session starts a few minutes after the operator checks in (and after
     * the outgoing operator has cleared the station) and ends when they
     * check out, so handoffs are staggered rather than simultaneous. In the
     * current block, a station without an active session is one whose
     * operator stepped away a little while ago.
     *
     * @param  array<int, array{station: Station, band: Band, mode: Mode, has_active: bool}>  $hfStations
     * @param  array<int, Shift>  $operatorShifts
     * @param  array<int, User>  $operators
     */
    private function seedHfSessions(EventConfiguration $config, array $hfStations, array $operatorShifts, array $operators): void
    {
        $latestStart = now()->subMinute();

        foreach ($hfStations as $position => $hf) {
            $previousEnd = null;

            foreach ($operatorShifts as $block => $shift) {
                if ($shift->start_time->isFuture()) {
                    break;
                }

                $operator = $operators[self::HF_OPERATOR_CREWS[$block % 2][$position]];
                $assignment = $shift->assignments->firstWhere('user_id', $operator->id);

                $start = $this->later($assignment?->checked_in_at ?? $shift->start_time, $shift->start_time)
                    ->addMinutes(random_int(1, 4));

                if ($previousEnd) {
                    $start = $this->later($start, $previousEnd->copy()->addMinutes(random_int(1, 3)));
                }

                $start = $this->earlier($start, $latestStart);

                if ($previousEnd) {
                    $start = $this->later($start, $previousEnd);
                }

                if ($shift->end_time->isFuture()) {
                    $end = null;

                    if (! $hf['has_active']) {
                        $end = now()->subMinutes(random_int(10, 30));

                        if ($end->lt($start->copy()->addMinutes(15))) {
                            continue;
                        }
                    }
                } else {
                    $end = $this->later($this->earlier($assignment?->checked_out_at ?? $shift->end_time, $latestStart), $start);
                }

                $count = (int) round(abs($start->diffInMinutes($end ?? now())) * random_int(10, 16) / 60);
                $this->seedSession($config, $hf['station'], $operator, $hf['band'], $hf['mode'], $start, $end, $count);

                $previousEnd = $end;
            }
        }
    }

    /**
     * @param  array<int, array{first_name: string, last_name: string, callsign: string|null}>|null  $gotaOperators
     */
    private function seedSession(
        EventConfiguration $config,
        Station $station,
        User $operator,
        Band $band,
        Mode $mode,
        Carbon $start,
        ?Carbon $end,
        int $contactCount,
        ?array $gotaOperators = null,
    ): void {
        $session = OperatingSession::create([
            'station_id' => $station->id,
            'operator_user_id' => $operator->id,
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'start_time' => $start,
            'end_time' => $end,
            'power_watts' => 100,
            'qso_count' => 0,
            'is_transcription' => false,
            'is_supervised' => $station->is_gota,
        ]);

        $this->seedContacts($config, $session, $contactCount, $start, $end ?? now(), $gotaOperators);

        $session->update(['qso_count' => $contactCount]);
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

        // Reassign most station radios to club ownership (heavy-club profile).
        // GOTA is absent on a WFD sandbox, so skip any station not present.
        foreach (['Station Alpha', 'Station Bravo', 'VHF/UHF', 'GOTA'] as $stationName) {
            $stations->get($stationName)?->primaryRadio->update([
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
            $station = $stations->get($stationName);

            if (! $station) {
                continue;
            }

            // Create EquipmentEvent for the station's primary radio
            EquipmentEvent::create([
                'equipment_id' => $station->radio_equipment_id,
                'event_id' => $event->id,
                'station_id' => $station->id,
                'assigned_by_user_id' => $manager->id,
                'status' => 'delivered',
                'committed_at' => $this->committedAt($event),
                'status_changed_at' => $this->deliveredAt($event),
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
                    'committed_at' => $this->committedAt($event),
                    'status_changed_at' => $this->deliveredAt($event),
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

            $committedAt = $this->committedAt($event);

            EquipmentEvent::create([
                'equipment_id' => $equipment->id,
                'event_id' => $event->id,
                'station_id' => null,
                'assigned_by_user_id' => $manager->id,
                'status' => 'committed',
                'committed_at' => $committedAt,
                'status_changed_at' => $committedAt,
            ]);
        }
    }

    /**
     * When gear was pledged to the event: an evening in the weeks before.
     */
    private function committedAt(Event $event): Carbon
    {
        return $event->start_time->copy()->subDays(random_int(3, 21))->setTime(random_int(18, 21), random_int(0, 59));
    }

    /**
     * When gear arrived on site: sometime during the setup window.
     */
    private function deliveredAt(Event $event): Carbon
    {
        return $event->start_time->copy()->subMinutes(random_int(30, 330));
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
        $windowSeconds = max(1, (int) $windowStart->diffInSeconds($windowEnd));

        $classPool = $this->exchangeClassPool();

        // Per-QSO points come from the mode's own rulebook column so a WFD
        // sandbox scores Phone at 1 and CW/Digital at 2 the way the WFDA SOP
        // does, rather than inheriting Field Day's values.
        $mode = Mode::find($session->mode_id);
        $contactPoints = (int) ($this->isWinter()
            ? ($mode?->points_wfd ?? 1)
            : ($mode?->points_fd ?? 1));

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
            if ($canadianSections->isNotEmpty() && CanadianSectionPicker::isCanadianContact()) {
                $callsign = CallsignGenerator::canada();
                $section = CanadianSectionPicker::pick($canadianSections);
            } else {
                $callsign = CallsignGenerator::us();
                $bucket = $weightedPool[array_rand($weightedPool)];
                $pool = $bucket === 'nearby' ? $nearbyPool : $midPool;
                $code = $pool[array_rand($pool)];
                $section = $allSections->get($code) ?? $allSections->random();
            }

            $exchangeClass = $this->makeExchangeClass($classPool);

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
                'exchange_class' => $exchangeClass,
                'power_watts' => 100,
                'is_gota_contact' => $session->is_supervised,
                'gota_operator_first_name' => $gotaOp['first_name'] ?? null,
                'gota_operator_last_name' => $gotaOp['last_name'] ?? null,
                'gota_operator_callsign' => $gotaOp['callsign'] ?? null,
                'is_natural_power' => false,
                'is_satellite' => false,
                'points' => $contactPoints,
                'is_duplicate' => false,
            ])->forceFill(['created_at' => $qsoTime, 'updated_at' => $qsoTime])->saveQuietly();
        }
    }

    /**
     * Weighted pool of exchange class letters for the seeded event type.
     *
     * FD classes run A–F with A dominant and F very rare. WFD uses H (home),
     * I (indoor), O (outdoor) and M (mobile) — home stations are by far the
     * most common entry in a January event, with outdoor a distant second.
     *
     * @return array<int, string>
     */
    private function exchangeClassPool(): array
    {
        if ($this->isWinter()) {
            return array_merge(
                array_fill(0, 55, 'H'),
                array_fill(0, 20, 'O'),
                array_fill(0, 15, 'I'),
                array_fill(0, 10, 'M'),
            );
        }

        return array_merge(
            array_fill(0, 50, 'A'),
            array_fill(0, 20, 'B'),
            array_fill(0, 15, 'C'),
            array_fill(0, 10, 'D'),
            array_fill(0, 4, 'E'),
            array_fill(0, 1, 'F'),
        );
    }

    /**
     * Build a received exchange class such as "3A" or "1H".
     *
     * Both rulebooks send a transmitter count ahead of the class letter. Only
     * classes that can plausibly run several transmitters get a count above 1.
     *
     * @param  array<int, string>  $classPool
     */
    private function makeExchangeClass(array $classPool): string
    {
        $letter = $classPool[array_rand($classPool)];

        return TransmitterCountGenerator::forClass($letter).$letter;
    }

    /**
     * Claim the bonuses a club would have in hand by now.
     *
     * Emergency power and the public location are set up and signed off
     * during setup. The media publicity claim went in after the event started
     * and is still waiting on the manager's review.
     */
    private function seedBonuses(EventConfiguration $config, User $manager, Event $event): void
    {
        if ($this->isWinter()) {
            $this->seedWinterObjectives($config, $manager, $event);

            return;
        }

        $verifiedCodes = ['emergency_power', 'public_location'];

        foreach ($verifiedCodes as $code) {
            $bonusType = BonusType::resolveFor($event, $code);
            if (! $bonusType) {
                continue;
            }

            $claimedAt = $event->start_time->copy()->subMinutes(random_int(60, 240));
            $verifiedAt = $claimedAt->copy()->addMinutes(random_int(10, 45));

            $bonus = EventBonus::create([
                'event_configuration_id' => $config->id,
                'bonus_type_id' => $bonusType->id,
                'claimed_by_user_id' => $manager->id,
                'quantity' => 1,
                'calculated_points' => $bonusType->base_points,
                'is_verified' => true,
                'verified_by_user_id' => $manager->id,
                'verified_at' => $verifiedAt,
            ]);
            $this->backdate($bonus, $claimedAt, ['updated_at' => $verifiedAt]);
        }

        $mediaBonusType = BonusType::resolveFor($event, 'media_publicity');
        if ($mediaBonusType) {
            $bonus = EventBonus::create([
                'event_configuration_id' => $config->id,
                'bonus_type_id' => $mediaBonusType->id,
                'claimed_by_user_id' => $manager->id,
                'quantity' => 1,
                'calculated_points' => 0,
                'notes' => 'Local newspaper article submitted for review',
                'is_verified' => false,
            ]);
            $this->backdate($bonus, $event->start_time->copy()->addMinutes(random_int(40, 100))->min(now()->subMinutes(5)));
        }
    }

    /**
     * Claim a couple of Winter Field Day objectives by hand.
     *
     * Only manually-claimed objectives are seeded. The derived ones —
     * alternative power, band and mode counts, QRP — are owned by their
     * strategies and reconciled from the logged contacts, so writing them here
     * would either duplicate or contradict what the scoring engine computes.
     * Both are set up before the operating period, so they are claimed and
     * verified during setup.
     */
    private function seedWinterObjectives(EventConfiguration $config, User $manager, Event $event): void
    {
        $claims = [
            'away_from_home' => 'Operating from the community center, 4 miles from the nearest member QTH.',
            'multiple_antennas' => 'G5RV and a 2m J-pole both raised during the setup window.',
        ];

        foreach ($claims as $code => $notes) {
            $bonusType = BonusType::resolveFor($event, $code);

            if (! $bonusType) {
                continue;
            }

            $claimedAt = $event->start_time->copy()->subMinutes(random_int(60, 240));
            $verifiedAt = $claimedAt->copy()->addMinutes(random_int(10, 45));

            $bonus = EventBonus::create([
                'event_configuration_id' => $config->id,
                'bonus_type_id' => $bonusType->id,
                'claimed_by_user_id' => $manager->id,
                'quantity' => 1,
                'calculated_points' => $bonusType->base_points,
                'notes' => $notes,
                'is_verified' => true,
                'verified_by_user_id' => $manager->id,
                'verified_at' => $verifiedAt,
            ]);
            $this->backdate($bonus, $claimedAt, ['updated_at' => $verifiedAt]);
        }
    }

    /**
     * Originate the radiograms a club sends early in the event: the Field Day
     * message to the Section Manager, and one a visitor wrote to their family.
     *
     * Both have been handed to the NTS net, so they earn the message bonuses.
     * Those derived bonuses are stamped with when the message went out, not
     * when the demo was built.
     */
    private function seedMessages(EventConfiguration $config, Event $event, User $handler): void
    {
        $eventName = $this->isWinter() ? 'WINTER FIELD DAY' : 'FIELD DAY';

        $messages = [
            [
                'is_sm_message' => true,
                'addressee_name' => 'Section Manager',
                'addressee_address' => 'ARRL Connecticut Section',
                'addressee_city' => null,
                'addressee_state' => 'CT',
                'addressee_zip' => null,
                'message_text' => "W1FDC DEMO RADIO CLUB OPERATING {$eventName} FROM HAMDEN WITH FOURTEEN OPERATORS X FOUR TRANSMITTERS ON EMERGENCY POWER X 73",
                'signature' => 'Emma Mitchell K4EM',
                'sent_to' => 'K1CTN',
                'frequency' => '3.640',
                'filed_after' => [10, 25],
            ],
            [
                'is_sm_message' => false,
                'addressee_name' => 'Ana Rivera',
                'addressee_address' => '18 Whitney Avenue',
                'addressee_city' => 'Hamden',
                'addressee_state' => 'CT',
                'addressee_zip' => '06517',
                'message_text' => "HAVING A GREAT TIME AT {$eventName} X I TALKED TO OHIO ON THE RADIO X SEE YOU AT DINNER LOVE",
                'signature' => 'Jamie',
                'sent_to' => 'K1CTN',
                'frequency' => '146.940',
                'filed_after' => [50, 80],
            ],
        ];

        // The Section Manager message is an ARRL Field Day bonus. Winter
        // Field Day has no equivalent, so only the visitor's message is sent.
        if ($this->isWinter()) {
            $messages = array_values(array_filter($messages, fn (array $def): bool => ! $def['is_sm_message']));
        }

        $sentAtByBonus = [];

        foreach ($messages as $index => $def) {
            $filedAt = $event->start_time->copy()->addMinutes(random_int(...$def['filed_after']))->min(now()->subMinutes(20));
            $sentAt = $filedAt->copy()->addMinutes(random_int(5, 15))->min(now()->subMinutes(5));

            $message = Message::create([
                'event_configuration_id' => $config->id,
                'user_id' => $handler->id,
                'format' => MessageFormat::Radiogram,
                'role' => MessageRole::Originated,
                'is_sm_message' => $def['is_sm_message'],
                'message_number' => $index + 1,
                'precedence' => MessagePrecedence::Routine,
                'station_of_origin' => 'W1FDC',
                'check' => (string) count(explode(' ', $def['message_text'])),
                'place_of_origin' => 'Hamden CT',
                'filed_at' => $filedAt,
                'addressee_name' => $def['addressee_name'],
                'addressee_address' => $def['addressee_address'],
                'addressee_city' => $def['addressee_city'],
                'addressee_state' => $def['addressee_state'],
                'addressee_zip' => $def['addressee_zip'],
                'message_text' => $def['message_text'],
                'signature' => $def['signature'],
                'sent_to' => $def['sent_to'],
                'sent_at' => $sentAt,
                'sent_by_user_id' => $handler->id,
                'frequency' => $def['frequency'],
                'mode_category' => 'Phone',
            ]);
            $this->backdate($message, $filedAt, ['updated_at' => $sentAt]);

            $sentAtByBonus[$def['is_sm_message'] ? 'sm_sec_message' : 'nts_message'] = $sentAt;
        }

        // Model events are switched off while seeding, so the observer that
        // would reconcile the message bonuses never fires. Raise it by hand.
        event(new MessageChanged($message, $config->id));

        foreach ($sentAtByBonus as $code => $sentAt) {
            $bonusType = BonusType::resolveFor($event, $code);
            $bonus = $bonusType
                ? EventBonus::where('event_configuration_id', $config->id)->where('bonus_type_id', $bonusType->id)->first()
                : null;

            if ($bonus) {
                $this->backdate($bonus, $sentAt, ['verified_at' => $sentAt]);
            }
        }
    }

    /**
     * Enter the bulletin transmissions the club plans to copy.
     *
     * Every entry is still ahead, so the bulletin bonus is left for the
     * visitor to earn, and the first one lands inside the reminder window.
     * Field Day uses W1AW's and K6KPH's published frequencies. The WFDA names
     * no fixed sender, so the Winter sked lists the association itself.
     */
    private function seedBulletinSchedule(Event $event, User $manager, Carbon $plannedAt): void
    {
        $frequencies = [
            'cw' => '3.5815, 7.0475, 14.0475, 21.0675, 28.0675',
            'digital' => '3.5975, 7.095, 14.095, 21.0975, 28.0975',
            'phone' => '3.990, 7.290, 14.290, 21.390, 28.590',
        ];

        $entries = $this->isWinter()
            ? [
                [3, 'phone', 'WFDA', 'Station Alpha copies, then we read it to visitors'],
                [6, 'cw', 'WFDA', 'Station Charlie copies'],
                [20, 'digital', 'WFDA', null],
            ]
            : [
                [3, 'phone', 'W1AW', 'Station Alpha copies'],
                [5, 'cw', 'W1AW', 'Station Charlie copies; good one for the CW demo'],
                [6, 'cw', 'K6KPH', 'West coast relay, backup if we miss W1AW'],
                [7, 'digital', 'W1AW', null],
                [21, 'phone', 'W1AW', 'Sunday morning repeat'],
            ];

        foreach ($entries as [$hours, $mode, $source, $notes]) {
            $entry = BulletinScheduleEntry::create([
                'event_id' => $event->id,
                'scheduled_at' => $event->start_time->copy()->addHours($hours),
                'mode' => $mode,
                'frequencies' => $frequencies[$mode],
                'source' => $source,
                'notes' => $notes,
                'created_by' => $manager->id,
            ]);
            $this->backdate($entry, $plannedAt->copy()->addDays(random_int(7, 20))->setTime(random_int(18, 21), random_int(0, 59)));
        }
    }

    /**
     * Work through most of the safety walk-through during setup, before the
     * operating period begins.
     */
    private function seedSafetyChecklist(User $captain, Event $event): void
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
                    'completed_at' => $event->start_time->copy()->subMinutes(random_int(15, 240)),
                ]);
        }
    }

    /**
     * Sign the guestbook over the hours the event has been running.
     *
     * Visitors arrive one by one rather than all at the moment the demo was
     * provisioned, and each entry is verified by the event manager a few
     * minutes after it was signed, never before. The newest signing is left
     * pending so the manager has one to review.
     */
    private function seedGuestbook(EventConfiguration $config, Event $event, User $manager): void
    {
        $entries = [
            // Licensed hams visiting in person
            [
                'callsign' => 'W1ABC', 'first_name' => 'Alice', 'last_name' => 'Brown',
                'email' => null, 'comments' => 'Great setup! Running four transmitters is impressive. 73!',
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

        $earliest = $event->start_time->copy()->addMinutes(10)->timestamp;
        $latest = now()->subMinutes(5)->timestamp;
        $signedAt = collect($entries)
            ->map(fn (): int => random_int($earliest, max($earliest, $latest)))
            ->sort()
            ->values();

        foreach ($entries as $index => $entry) {
            $createdAt = Carbon::createFromTimestamp($signedAt[$index]);
            $isPending = $index === count($entries) - 1;

            $guestbookEntry = new GuestbookEntry([
                'event_configuration_id' => $config->id,
                'callsign' => $entry['callsign'],
                'first_name' => $entry['first_name'],
                'last_name' => $entry['last_name'],
                'email' => $entry['email'],
                'comments' => $entry['comments'],
                'presence_type' => $entry['presence_type'],
                'visitor_category' => $entry['visitor_category'],
                'is_verified' => ! $isPending,
                'verified_by' => $isPending ? null : $manager->id,
                'verified_at' => $isPending ? null : $createdAt->copy()->addMinutes(random_int(2, 20))->min(now()),
            ]);
            $guestbookEntry->created_at = $createdAt;
            $guestbookEntry->updated_at = $guestbookEntry->verified_at ?? $createdAt;
            $guestbookEntry->save();
        }
    }

    /**
     * Shift roles for a Winter Field Day sandbox.
     *
     * WFD has no GOTA station, no safety-officer bonus and no public
     * information table, so only the roles that describe running the event
     * itself carry over. Values come from {@see ShiftRole::DEFAULTS} so the
     * descriptions, icons and colors stay in one place.
     */
    private function seedWinterShiftRoles(EventConfiguration $config): void
    {
        $carriedOver = ['Event Manager', self::ROLE_STATION_CAPTAIN, 'Operator', 'Message Handler', 'Site Responsibilities'];

        $sortOrder = 0;
        foreach ($carriedOver as $name) {
            $defaults = ShiftRole::DEFAULTS[$name];

            ShiftRole::firstOrCreate(
                [
                    'event_configuration_id' => $config->id,
                    'name' => $name,
                ],
                [
                    'description' => $defaults['description'],
                    'is_default' => true,
                    // WFD awards objective multipliers, not per-role bonus
                    // points, so no role carries points into a WFD event.
                    'bonus_points' => null,
                    'requires_confirmation' => $defaults['requires_confirmation'],
                    'icon' => $defaults['icon'],
                    'color' => $defaults['color'],
                    'sort_order' => $sortOrder,
                ]
            );

            $sortOrder++;
        }
    }

    /**
     * Lay out the volunteer schedule and return the operator shifts in order.
     *
     * The two HF crews alternate in 2-hour blocks across the whole event, and
     * nobody holds two jobs at the same moment: GOTA coaching, greeting and
     * VHF fall to the operators outside the HF rotation.
     *
     * @param  array<int, User>  $operators
     * @return array<int, Shift>
     */
    private function seedShifts(
        EventConfiguration $config,
        Event $event,
        User $admin,
        User $manager,
        User $captain1,
        User $captain2,
        array $operators
    ): array {
        // Setup is club-specific work rather than a bonus role, so it is a
        // custom role alongside the defaults.
        ShiftRole::create([
            'event_configuration_id' => $config->id,
            'name' => 'Site Setup',
            'description' => 'Raises antennas, runs power and sets up the stations before the operating period',
            'is_default' => false,
            'bonus_points' => null,
            'requires_confirmation' => false,
            'icon' => 'phosphor-wrench',
            'color' => '#78716c',
            'sort_order' => ShiftRole::where('event_configuration_id', $config->id)->max('sort_order') + 1,
        ]);

        $roles = ShiftRole::where('event_configuration_id', $config->id)
            ->get()
            ->keyBy('name');

        // Every block is laid out from the event start (always on the hour),
        // so the schedule reads like one a club would actually publish.
        $at = fn (int $hours): Carbon => $event->start_time->copy()->addHours($hours);

        // ── Site setup: two crews in the 6 hours before the start ────────────
        // Antennas go up first, then stations and power. Each crew has one
        // spot left open for a late volunteer.
        $antennaCrew = $this->makeShift($config, $roles['Site Setup'], $at(-6), $at(-3), 6, 'Antennas, masts and guy lines');
        foreach ([$captain2, $operators[0], $operators[1], $operators[4], $operators[5]] as $user) {
            $this->assign($antennaCrew, $user);
        }

        $stationCrew = $this->makeShift($config, $roles['Site Setup'], $at(-3), $at(0), 6, 'Stations, generator and power distribution');
        foreach ([$captain2, $operators[2], $operators[3], $operators[6], $operators[7]] as $user) {
            $this->assign($stationCrew, $user);
        }

        // Someone watches for hazards the whole time setup is underway. WFD
        // has no Safety Officer role, so Site Responsibilities covers it there.
        $setupSafetyRole = $roles->get('Safety Officer') ?? $roles->get('Site Responsibilities');
        if ($setupSafetyRole) {
            $setupSafety = $this->makeShift($config, $setupSafetyRole, $at(-6), $at(0), 1, 'Setup safety: antenna raising, guying, generator and fuel');
            $this->assign($setupSafety, $captain1);
        }

        // ── Operator shifts: 2-hour blocks, crews alternating ─────────────────
        // One spot per block is left open for a walk-in volunteer.
        $operatorShifts = [];

        if ($roles->has('Operator')) {
            for ($block = 0; $block < 12; $block++) {
                $shift = $this->makeShift($config, $roles['Operator'], $at($block * 2), $at($block * 2 + 2), 5);

                foreach (self::HF_OPERATOR_CREWS[$block % 2] as $i) {
                    $this->assign($shift, $operators[$i]);
                }

                $operatorShifts[] = $shift->load('assignments');
            }
        }

        // ── Station Captain: two 12-hour halves ──────────────────────────────
        if ($roles->has(self::ROLE_STATION_CAPTAIN)) {
            $cap1Shift = $this->makeShift($config, $roles[self::ROLE_STATION_CAPTAIN], $at(0), $at(12), 2);
            $this->assign($cap1Shift, $captain1);

            $cap2Shift = $this->makeShift($config, $roles[self::ROLE_STATION_CAPTAIN], $at(12), $at(24), 2);
            $this->assign($cap2Shift, $captain2);
        }

        // ── Event Manager: full event ─────────────────────────────────────────
        if ($roles->has('Event Manager')) {
            $mgr = $this->makeShift($config, $roles['Event Manager'], $at(0), $at(24), 1);
            $this->assign($mgr, $manager);
        }

        // ── Safety Officer: two people, 12-hour handoff ────────────────────────
        if ($roles->has('Safety Officer')) {
            $so1 = $this->makeShift($config, $roles['Safety Officer'], $at(0), $at(12), 1);
            $this->assign($so1, $captain1);

            $so2 = $this->makeShift($config, $roles['Safety Officer'], $at(12), $at(24), 1);
            $this->assign($so2, $admin);
        }

        // ── GOTA Coach: daytime coverage ──────────────────────────────────────
        if ($roles->has('GOTA Coach')) {
            $gc1 = $this->makeShift($config, $roles['GOTA Coach'], $at(0), $at(8), 1);
            $this->assign($gc1, $operators[8]);

            $gc2 = $this->makeShift($config, $roles['GOTA Coach'], $at(8), $at(16), 1);
            $this->assign($gc2, $operators[9]);
        }

        // ── Public Greeter: afternoon visiting hours, one spot still open ────
        if ($roles->has('Public Greeter')) {
            $pg = $this->makeShift($config, $roles['Public Greeter'], $at(2), $at(8), 2);
            $this->assign($pg, $operators[9]);
        }

        // ── Site Responsibilities: one person, full event ─────────────────────
        if ($roles->has('Site Responsibilities')) {
            $site = $this->makeShift($config, $roles['Site Responsibilities'], $at(0), $at(24), 1);
            $this->assign($site, $captain2);
        }

        // ── Message Handler: from the start, to send the SM message early ─────
        if ($roles->has('Message Handler')) {
            $msg = $this->makeShift($config, $roles['Message Handler'], $at(0), $at(8), 1);
            $this->assign($msg, $admin);
        }

        return $operatorShifts;
    }

    /**
     * Rebuild the notification feed as it would have filled in during the
     * event so far.
     *
     * The notifying observers are silenced while seeding, so this walks the
     * seeded sessions, contacts and guestbook in time order and writes the
     * notifications they would have raised, batched the way
     * NotificationService debounces them. They are written straight to the
     * table rather than through the service so seeding never broadcasts.
     * Anything older than half an hour has already been read.
     */
    private function seedNotificationHistory(EventConfiguration $config): void
    {
        $timeline = collect();
        $push = function (Carbon $at, NotificationCategory $category, string $title, string $message, string $url, string $groupKey) use ($timeline): void {
            $timeline->push(compact('at', 'category', 'title', 'message', 'url', 'groupKey'));
        };

        OperatingSession::with(['operator', 'station'])->get()->each(function (OperatingSession $session) use ($push) {
            $stationName = $session->station?->name ?? 'a station';
            $groupKey = "station_status_{$session->station_id}";

            $push($session->start_time, NotificationCategory::StationStatus, 'Station Occupied',
                ($session->operator?->call_sign ?? 'Someone')." started operating at {$stationName}", '/stations', $groupKey);

            if ($session->end_time) {
                $push($session->end_time, NotificationCategory::StationStatus, 'Station Available',
                    "{$stationName} is now available", '/stations', $groupKey);
            }
        });

        $sectionsWorked = [];
        $contactsLogged = 0;

        Contact::with(['section', 'band', 'mode', 'operatingSession.operator'])
            ->orderBy('qso_time')
            ->orderBy('id')
            ->get()
            ->each(function (Contact $contact) use ($push, $config, &$sectionsWorked, &$contactsLogged) {
                $contactsLogged++;

                if (! isset($sectionsWorked[$contact->section_id])) {
                    $sectionsWorked[$contact->section_id] = true;
                    $opCall = $contact->operatingSession?->operator?->call_sign ?? 'Unknown';

                    $push($contact->qso_time, NotificationCategory::NewSection, 'New Section Worked!',
                        "{$opCall} worked {$contact->section?->code} on {$contact->band?->name} {$contact->mode?->name}",
                        '/logbook', "new_section_event_{$config->id}");
                }

                if ($contactsLogged % 50 === 0) {
                    $push($contact->qso_time, NotificationCategory::QsoMilestone, 'QSO Milestone!',
                        "Congratulations! {$contactsLogged} QSOs logged for this event!", '/logbook', "qso_milestone_{$contactsLogged}");
                }
            });

        GuestbookEntry::all()->each(function (GuestbookEntry $entry) use ($push) {
            $visitorName = trim("{$entry->first_name} {$entry->last_name}");
            $callsign = $entry->callsign ? " ({$entry->callsign})" : '';

            $push($entry->created_at, NotificationCategory::Guestbook, 'New Guestbook Entry',
                "{$visitorName}{$callsign} signed the guestbook", '/guestbook', 'guestbook_entries');
        });

        $notifications = [];
        $latestByGroup = [];

        foreach ($timeline->sortBy(fn (array $item): int => $item['at']->getTimestamp())->values() as $item) {
            $index = $latestByGroup[$item['groupKey']] ?? null;
            $debounceSeconds = $item['category']->debounceSeconds();

            if ($index !== null && $debounceSeconds > 0
                && $item['at']->getTimestamp() - $notifications[$index]['at']->getTimestamp() <= $debounceSeconds) {
                $notifications[$index]['count']++;
                $notifications[$index]['message'] = $item['message'];
                $notifications[$index]['title'] = $item['category']->batchedTitle($notifications[$index]['count']) ?? $notifications[$index]['title'];
                $notifications[$index]['updatedAt'] = $item['at'];

                continue;
            }

            $latestByGroup[$item['groupKey']] = count($notifications);
            $notifications[] = $item + ['count' => 1, 'updatedAt' => $item['at']];
        }

        $readBefore = now()->subMinutes(30);
        $rows = [];

        foreach (User::all() as $user) {
            foreach ($notifications as $notification) {
                if (! $user->isSubscribedTo($notification['category'])) {
                    continue;
                }

                $payload = (new InAppNotification(
                    category: $notification['category'],
                    title: $notification['title'],
                    message: $notification['message'],
                    url: $notification['url'],
                    groupKey: $notification['groupKey'],
                    count: $notification['count'],
                ))->toArray($user);

                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'type' => InAppNotification::class,
                    'notifiable_type' => $user->getMorphClass(),
                    'notifiable_id' => $user->id,
                    'data' => json_encode($payload),
                    'read_at' => $notification['updatedAt']->lt($readBefore)
                        ? $notification['updatedAt']->copy()->addMinutes(random_int(1, 15))
                        : null,
                    'created_at' => $notification['at'],
                    'updated_at' => $notification['updatedAt'],
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }
    }

    private function makeShift(
        EventConfiguration $config,
        ShiftRole $role,
        Carbon $start,
        Carbon $end,
        int $capacity,
        ?string $notes = null,
    ): Shift {
        return Shift::create([
            'event_configuration_id' => $config->id,
            'shift_role_id' => $role->id,
            'start_time' => $start,
            'end_time' => $end,
            'capacity' => $capacity,
            'is_open' => true,
            'notes' => $notes,
        ]);
    }

    /**
     * Assign a user with attendance that matches where the shift sits in time.
     *
     * People rarely arrive or leave on the exact minute, so finished and
     * in-progress shifts get check-in/out times scattered around the
     * scheduled boundaries. Future shifts stay scheduled for the simulator
     * (or the visitor) to check in later.
     */
    private function assign(Shift $shift, User $user): void
    {
        $status = ShiftAssignment::STATUS_SCHEDULED;
        $checkedInAt = null;
        $checkedOutAt = null;

        if ($shift->start_time->isPast()) {
            $status = ShiftAssignment::STATUS_CHECKED_IN;
            $checkedInAt = $shift->start_time->copy()->addMinutes(random_int(-10, 8))->min(now());
        }

        if ($shift->end_time->isPast()) {
            $status = ShiftAssignment::STATUS_CHECKED_OUT;
            $checkedOutAt = $shift->end_time->copy()->addMinutes(random_int(-5, 12))->min(now());
        }

        ShiftAssignment::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => $status,
            'signup_type' => ShiftAssignment::SIGNUP_TYPE_ASSIGNED,
            'checked_in_at' => $checkedInAt,
            'checked_out_at' => $checkedOutAt,
        ]);
    }
}
