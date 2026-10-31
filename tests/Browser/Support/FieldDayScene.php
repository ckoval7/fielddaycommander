<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Browser\Support;

use App\Enums\VisitorCategory;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\GuestbookEntry;
use App\Models\Message;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Organization;
use App\Models\Section;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * An event in progress with enough data that every screen renders its full
 * layout: stations, an open operating session, contacts across bands and
 * modes, operators, shifts (including ended shifts nobody resolved), guestbook
 * entries, personal and club equipment, and message traffic.
 *
 * Faker is seeded, so names, callsigns and other generated text are the same
 * on every run and failures point at the same elements.
 */
final class FieldDayScene
{
    public function __construct(
        public readonly User $admin,
        public readonly Event $event,
        public readonly EventConfiguration $config,
        public readonly Station $station,
        public readonly OperatingSession $session,
    ) {}

    /**
     * @param  string  $eventType  FD for Field Day or WFD for Winter Field Day.
     */
    public static function create(string $eventType = 'FD'): self
    {
        fake()->seed(20260627);
        mt_srand(20260627);

        test()->seed(DatabaseSeeder::class);

        DB::table('system_config')->updateOrInsert(['key' => 'setup_completed'], ['value' => 'true']);

        $admin = User::factory()->create([
            'call_sign' => 'W1AW',
            'first_name' => 'Hiram',
            'last_name' => 'Maxim',
            'email' => 'w1aw@example.com',
            'password_changed_at' => now(),
        ]);
        $admin->syncRoles(['System Administrator']);
        $operators = User::factory()->count(5)->create(['password_changed_at' => now()]);
        $operators->each(fn (User $user) => $user->syncRoles(['Operator']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $type = EventType::where('code', $eventType)->firstOrFail();
        $isWinter = $eventType === 'WFD';
        $event = Event::factory()->create([
            'name' => ($isWinter ? 'Winter Field Day' : 'Field Day').' 2026 Browser Test',
            'event_type_id' => $type->id,
            'year' => 2026,
            'start_time' => now()->subHours(6),
            'end_time' => now()->addHours(18),
            'setup_allowed_from' => now()->subHours(30),
        ]);
        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
            'created_by_user_id' => $admin->id,
            'callsign' => 'W1AW',
            'section_id' => Section::where('code', 'CT')->value('id'),
            'operating_class_id' => OperatingClass::where('event_type_id', $type->id)->where('code', $isWinter ? 'O' : 'A')->value('id'),
            'transmitter_count' => 3,
            'is_active' => true,
            'guestbook_enabled' => true,
        ]);

        $stations = collect(['Phone 1', 'CW 1', 'Digital 1'])->map(fn (string $name) => Station::factory()->create([
            'event_configuration_id' => $config->id,
            'name' => $name,
        ]));
        $station = $stations->first();
        $session = OperatingSession::factory()->active()->create([
            'station_id' => $station->id,
            'operator_user_id' => $admin->id,
            'band_id' => Band::where('name', '20m')->value('id'),
            'mode_id' => Mode::where('name', 'Phone')->value('id'),
            'power_watts' => 100,
            'qso_count' => 0,
        ]);

        self::contacts($config, $session, $admin);
        self::shifts($config, $admin, $operators->all());
        self::guestbook($config, $admin);
        self::equipment($event, $stations->all(), $admin);
        Message::factory()->count(3)->create(['event_configuration_id' => $config->id, 'user_id' => $admin->id]);

        return new self($admin, $event, $config, $station, $session);
    }

    /**
     * Fills {event}, {config}, {station} and {session} in a path.
     */
    public function path(string $template): string
    {
        return strtr($template, [
            '{event}' => (string) $this->event->id,
            '{config}' => (string) $this->config->id,
            '{station}' => (string) $this->station->id,
            '{session}' => (string) $this->session->id,
        ]);
    }

    private static function contacts(EventConfiguration $config, OperatingSession $session, User $logger): void
    {
        $bands = Band::whereIn('name', ['80m', '40m', '20m', '15m', '10m', '2m'])->pluck('id')->all();
        $modes = Mode::pluck('id')->all();
        $sections = Section::pluck('id')->all();
        $callsigns = ['K1ABC', 'N2XYZ', 'W3LONGCALL/QRP', 'VE3AAA', 'KD9QRS', 'VA7/DL1ABC/P'];

        for ($i = 0; $i < 40; $i++) {
            Contact::factory()->create([
                'event_configuration_id' => $config->id,
                'operating_session_id' => $session->id,
                'logger_user_id' => $logger->id,
                'callsign' => $callsigns[$i] ?? strtoupper(fake()->bothify('?#???')),
                'band_id' => $bands[$i % count($bands)],
                'mode_id' => $modes[$i % count($modes)],
                'section_id' => $sections[$i % count($sections)],
                'qso_time' => now()->subMinutes(5 + $i * 7),
            ]);
        }
    }

    /**
     * @param  list<User>  $operators
     */
    private static function shifts(EventConfiguration $config, User $admin, array $operators): void
    {
        $roles = collect(['Station Operator', 'Safety Officer', 'Public Information Table'])
            ->map(fn (string $name, int $i) => ShiftRole::factory()->create([
                'event_configuration_id' => $config->id,
                'name' => $name,
                'sort_order' => $i,
            ]));

        // Ended shifts whose assignments were never marked worked or no-show.
        foreach ([-8, -5] as $hoursAgo) {
            $shift = Shift::factory()->create([
                'event_configuration_id' => $config->id,
                'shift_role_id' => $roles[0]->id,
                'start_time' => now()->addHours($hoursAgo),
                'end_time' => now()->addHours($hoursAgo + 2),
                'capacity' => 2,
            ]);
            ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'user_id' => $operators[0]->id]);
            ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'user_id' => $operators[1]->id]);
        }

        $current = Shift::factory()->create([
            'event_configuration_id' => $config->id,
            'shift_role_id' => $roles[1]->id,
            'start_time' => now()->subHour(),
            'end_time' => now()->addHours(2),
        ]);
        ShiftAssignment::factory()->checkedIn()->create(['shift_id' => $current->id, 'user_id' => $admin->id]);

        foreach ($roles as $i => $role) {
            Shift::factory()->open()->create([
                'event_configuration_id' => $config->id,
                'shift_role_id' => $role->id,
                'start_time' => now()->addHours(3 + $i * 2),
                'end_time' => now()->addHours(5 + $i * 2),
                'capacity' => 3,
            ]);
        }
    }

    private static function guestbook(EventConfiguration $config, User $admin): void
    {
        foreach (VisitorCategory::cases() as $i => $category) {
            GuestbookEntry::factory()->create([
                'event_configuration_id' => $config->id,
                'visitor_category' => $category,
                'comments' => $i === 0 ? 'Stopped by with the scout troop and stayed for the whole 20 meter pileup; thank you all!' : null,
                'is_verified' => $i % 2 === 0,
                'verified_by' => $i % 2 === 0 ? $admin->id : null,
                'verified_at' => $i % 2 === 0 ? now() : null,
            ]);
        }
    }

    /**
     * @param  list<Station>  $stations
     */
    private static function equipment(Event $event, array $stations, User $admin): void
    {
        $club = Organization::factory()->create(['name' => 'Test Radio Club']);
        $items = collect([
            Equipment::factory()->create(['owner_user_id' => $admin->id, 'make' => 'Yaesu', 'model' => 'FT-991A']),
            Equipment::factory()->create(['owner_user_id' => $admin->id, 'make' => 'Bioenno', 'model' => 'BLF-1220A']),
        ])->merge(Equipment::factory()->count(4)->create(['owner_user_id' => null, 'owner_organization_id' => $club->id]));

        foreach ($items as $i => $item) {
            EquipmentEvent::factory()->create([
                'equipment_id' => $item->id,
                'event_id' => $event->id,
                'station_id' => $stations[$i % count($stations)]->id,
            ]);
        }
    }
}
