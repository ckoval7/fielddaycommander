<?php

// Copied into tests/Feature by render.sh, run once, then deleted. Not a real test:
// it seeds enough data to fill several pages and writes every app PDF to
// PDF_RENDER_OUT so the pages can be rasterized and looked at.

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Models\W1awBulletin;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed', 'value' => 'true', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->seed(EventTypeSeeder::class);
    $this->seed(BonusTypeSeeder::class);

    Permission::firstOrCreate(['name' => 'view-reports']);
    Permission::firstOrCreate(['name' => 'manage-event-equipment']);
    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['view-reports', 'manage-event-equipment']);

    $this->out = rtrim(getenv('PDF_RENDER_OUT') ?: '/tmp/pdf-render', '/');
    $this->long = getenv('PDF_RENDER_LONG') !== '0';

    $this->makeEvent = function (string $typeCode, string $version, array $overrides = []): EventConfiguration {
        $type = EventType::where('code', $typeCode)->firstOrFail();
        $class = OperatingClass::create([
            'event_type_id' => $type->id,
            'code' => $typeCode === 'WFD' ? 'O' : 'A',
            'name' => 'Class',
            'allows_gota' => $typeCode === 'FD',
        ]);
        $event = Event::factory()->create([
            'event_type_id' => $type->id,
            'rules_version' => $version,
            'start_time' => now()->subHours(12),
            'end_time' => now()->addHours(12),
        ]);

        return EventConfiguration::factory()->create(array_merge([
            'event_id' => $event->id,
            'callsign' => 'W1AW',
            'club_name' => 'Anytown Amateur Radio Club',
            'section_id' => Section::factory()->create(['code' => 'CT'])->id,
            'operating_class_id' => $class->id,
            'transmitter_count' => 3,
            'max_power_watts' => 100,
        ], $overrides));
    };

    $this->save = function (string $name, $response): void {
        $response->assertOk();
        expect($response->getContent())->toStartWith('%PDF-');
        file_put_contents("{$this->out}/{$name}.pdf", $response->getContent());
    };

    $this->bulletin = fn (EventConfiguration $config) => W1awBulletin::factory()->create([
        'event_configuration_id' => $config->id,
        'bulletin_text' => str_repeat("QST DE W1AW FIELD DAY BULLETIN TEXT AS COPIED BY THE OPERATOR\n", $this->long ? 150 : 6),
    ]);
});

test('ARRL submission sheet', function () {
    $config = ($this->makeEvent)('FD', (string) now()->year, ['has_gota_station' => true, 'gota_callsign' => 'W1AW/G']);
    ($this->bulletin)($config);

    ($this->save)('submission-sheet', $this->actingAs($this->user)->get(route('reports.submission-sheet')));
});

test('WFD event summary', function () {
    $config = ($this->makeEvent)('WFD', '2027', ['callsign' => 'K4SCO/P']);
    ($this->bulletin)($config);

    ($this->save)('wfd-event-summary', $this->actingAs($this->user)->get(route('reports.wfd-event-summary')));
});

test('equipment reports', function () {
    $config = ($this->makeEvent)('FD', (string) now()->year);
    $stations = Station::factory()->count(3)->create(['event_configuration_id' => $config->id]);
    $statuses = ['committed', 'delivered', 'damaged', 'lost', 'returned', 'cancelled'];
    $owners = User::factory()->count($this->long ? 40 : 4)->create();

    foreach (range(1, $this->long ? 150 : 12) as $i) {
        EquipmentEvent::factory()->create([
            'event_id' => $config->event_id,
            'equipment_id' => Equipment::factory()->create(['owner_user_id' => $owners[$i % $owners->count()]->id])->id,
            // Every fifth item is unassigned so the inventory's last section renders too.
            'station_id' => $i % 5 === 0 ? null : $stations[$i % 3]->id,
            'status' => $statuses[$i % count($statuses)],
        ]);
    }

    foreach (['delivery-checklist', 'station-inventory-pdf', 'owner-contacts-pdf', 'return-checklist', 'incident-report-pdf'] as $report) {
        ($this->save)(
            'equipment-'.str_replace('-pdf', '', $report),
            $this->actingAs($this->user)->get(route("events.equipment.reports.{$report}", ['event' => $config->event_id])),
        );
    }
});
