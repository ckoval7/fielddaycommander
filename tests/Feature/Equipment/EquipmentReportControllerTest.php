<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Models\User;
use App\Services\EquipmentReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mark system setup as complete
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create permission
    Permission::firstOrCreate(['name' => 'manage-event-equipment']);

    // Create admin user with manage-event-equipment permission
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo('manage-event-equipment');

    // Create event
    $this->event = Event::factory()->create();

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    // Create station
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
    ]);

    // Create equipment owner
    $this->owner = User::factory()->create();

    // Create equipment with commitment
    $this->equipment = Equipment::factory()->create([
        'owner_user_id' => $this->owner->id,
    ]);

    $this->commitment = EquipmentEvent::factory()->create([
        'event_id' => $this->event->id,
        'equipment_id' => $this->equipment->id,
        'station_id' => $this->station->id,
        'status' => 'delivered',
    ]);
});

test('commitment summary export requires authentication', function () {
    $response = $this->get(route('events.equipment.reports.commitment-summary', ['event' => $this->event]));

    $response->assertRedirect(route('login'));
});

test('commitment summary export requires permission', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('events.equipment.reports.commitment-summary', ['event' => $this->event]));

    $response->assertForbidden();
});

test('commitment summary export returns csv', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.commitment-summary', ['event' => $this->event]));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain('.csv');
});

test('commitment summary csv contains equipment data', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.commitment-summary', ['event' => $this->event]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)
        ->toContain('Equipment Commitment Summary')
        ->toContain($this->event->name)
        ->toContain($this->equipment->make)
        ->toContain($this->equipment->model);
});

test('delivery checklist export returns pdf', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.delivery-checklist', ['event' => $this->event]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('application/pdf');
    expect($response->headers->get('content-disposition'))->toStartWith('inline')->toContain('.pdf');
    expect($response->getContent())->toStartWith('%PDF-');
});

test('station inventory pdf export returns pdf', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.station-inventory-pdf', ['event' => $this->event]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
    expect($response->getContent())->toStartWith('%PDF-');
});

test('station inventory csv export returns csv', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.station-inventory-csv', ['event' => $this->event]));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('station inventory csv contains station data', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.station-inventory-csv', ['event' => $this->event]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)
        ->toContain('Station Equipment Inventory')
        ->toContain($this->station->name)
        ->toContain($this->equipment->make);
});

test('owner contacts pdf export returns pdf', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.owner-contacts-pdf', ['event' => $this->event]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
    expect($response->getContent())->toStartWith('%PDF-');
});

test('owner contacts csv export returns csv', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.owner-contacts-csv', ['event' => $this->event]));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('owner contacts csv contains contact data', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.owner-contacts-csv', ['event' => $this->event]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)
        ->toContain('Equipment Owner Contact List')
        ->toContain($this->owner->call_sign);
});

test('return checklist export returns pdf', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.return-checklist', ['event' => $this->event]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
    expect($response->getContent())->toStartWith('%PDF-');
});

test('incident report pdf export returns pdf', function () {
    // Set equipment to damaged status
    $this->commitment->update(['status' => 'damaged']);

    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.incident-report-pdf', ['event' => $this->event]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toStartWith('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
    expect($response->getContent())->toStartWith('%PDF-');
});

test('incident report csv export returns csv', function () {
    // Set equipment to lost status
    $this->commitment->update(['status' => 'lost']);

    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.incident-report-csv', ['event' => $this->event]));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('incident report csv contains incident data', function () {
    // Set equipment to damaged status
    $this->commitment->update(['status' => 'damaged']);

    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.incident-report-csv', ['event' => $this->event]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)
        ->toContain('Equipment Incident Report')
        ->toContain($this->equipment->make)
        ->toContain('Damaged');
});

test('historical record export returns csv', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.historical-record', ['event' => $this->event]));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('historical record csv contains complete data', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.historical-record', ['event' => $this->event]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)
        ->toContain('Equipment Historical Record')
        ->toContain($this->event->name)
        ->toContain($this->equipment->make)
        ->toContain($this->equipment->model);
});

test('pdf header includes both club name and event name', function () {
    $this->eventConfig->update(['club_name' => 'Acme Repeater Society']);

    $service = app(EquipmentReportService::class);
    $data = $service->generateDeliveryChecklist($this->event->id);

    $html = view('equipment.reports.delivery-checklist', $data + ['generated_at' => now()])->render();

    expect($html)
        ->toContain('Acme Repeater Society')
        ->toContain($this->event->name);
});

test('pdf reports keep their page margins so rows stay clear of the footer', function () {
    $service = app(EquipmentReportService::class);
    $data = $service->generateStationInventory($this->event->id);

    $pdf = Pdf::loadView('equipment.reports.station-inventory', $data + ['generated_at' => now()])
        ->setPaper('letter', 'portrait');
    $pdf->render();

    // dompdf takes page margins from the root element's style, which a
    // universal margin reset in the layout would zero out.
    $rootStyle = $pdf->getDomPDF()->getTree()->get_root()->get_style();

    expect((float) $rootStyle->length_in_pt($rootStyle->margin_top))->toBeGreaterThan(0)
        ->and((float) $rootStyle->length_in_pt($rootStyle->margin_bottom))->toBeGreaterThan(0);
});

test('owner contacts pdf fills each page before starting the next', function () {
    // 60 one-line rows (18.2pt each) fit on two pages: 32 under the page 1
    // banner and the rest on page 2. The old fixed 20pt estimate and budgets
    // spread them over three pages, leaving each about three quarters full.
    $contacts = collect(range(1, 60))->map(fn (int $i) => [
        'owner_name' => "Owner {$i}",
        'callsign' => 'W1AW',
        'email' => "owner{$i}@example.com",
        'emergency_contacts' => collect(['555-0100']),
        'equipment_count' => 1,
    ]);

    $html = view('equipment.reports.owner-contacts', [
        'event' => $this->event,
        'contacts' => $contacts,
        'generated_at' => now(),
    ])->render();

    expect(substr_count($html, 'style="page-break-before: always;"'))->toBe(1);
});

test('owner contacts pdf sizes rows by their wrapped emergency contacts', function () {
    // Four phone numbers wrap to two lines, so fewer rows fit per page.
    $contacts = collect(range(1, 56))->map(fn (int $i) => [
        'owner_name' => "Owner {$i}",
        'callsign' => 'W1AW',
        'email' => "owner{$i}@example.com",
        'emergency_contacts' => collect(['(480) 227-3882', '719-829-9328', '+1.952.500.7645', '959-513-1297']),
        'equipment_count' => 4,
    ]);

    $html = view('equipment.reports.owner-contacts', [
        'event' => $this->event,
        'contacts' => $contacts,
        'generated_at' => now(),
    ])->render();

    // 31.2pt rows: 18 on page 1 and 20 on each later page, so three pages.
    // The old fixed estimate packed 26 of them onto page 1, which overflowed.
    expect(substr_count($html, 'style="page-break-before: always;"'))->toBe(2);
});

test('export filenames contain event name and date', function () {
    $response = $this->actingAs($this->admin)->get(route('events.equipment.reports.commitment-summary', ['event' => $this->event]));

    $response->assertOk();
    $disposition = $response->headers->get('content-disposition');
    expect($disposition)
        ->toContain('commitment-summary')
        ->toContain(now()->format('Y-m-d'));
});

test('all report routes are registered', function () {
    $routes = [
        'events.equipment.reports.commitment-summary',
        'events.equipment.reports.delivery-checklist',
        'events.equipment.reports.station-inventory-pdf',
        'events.equipment.reports.station-inventory-csv',
        'events.equipment.reports.owner-contacts-pdf',
        'events.equipment.reports.owner-contacts-csv',
        'events.equipment.reports.return-checklist',
        'events.equipment.reports.incident-report-pdf',
        'events.equipment.reports.incident-report-csv',
        'events.equipment.reports.historical-record',
    ];

    foreach ($routes as $routeName) {
        expect(route($routeName, ['event' => $this->event]))->toBeString();
    }
});

test('commitment summary csv neutralizes spreadsheet formulas', function () {
    $this->equipment->update(['make' => '+cmd|" /C calc"!A0']);

    $content = $this->actingAs($this->admin)
        ->get(route('events.equipment.reports.commitment-summary', ['event' => $this->event]))
        ->streamedContent();

    expect($content)->toContain('"\'+cmd|"" /C calc""!A0"')
        ->not->toContain(',"+cmd');
});

test('equipment reports and dashboard still work after a past event station primary radio is deleted', function (string $routeName) {
    $this->event->update([
        'start_time' => now()->subYear(),
        'end_time' => now()->subYear()->addDay(),
    ]);
    $this->commitment->update(['status' => 'returned']);

    // The station observer commits the primary radio to the station's event.
    $radio = Equipment::factory()->create([
        'owner_user_id' => $this->owner->id,
        'type' => 'radio',
        'make' => 'Kenwood',
        'model' => 'TS-590SG',
    ]);
    $this->station->update(['radio_equipment_id' => $radio->id]);
    EquipmentEvent::where('equipment_id', $radio->id)->update(['status' => 'delivered']);

    $radio->delete();

    $this->withoutVite()
        ->actingAs($this->admin)
        ->get(route($routeName, ['event' => $this->event]))
        ->assertOk();
})->with([
    'events.equipment.dashboard',
    'events.equipment.reports.commitment-summary',
    'events.equipment.reports.delivery-checklist',
    'events.equipment.reports.station-inventory-pdf',
    'events.equipment.reports.station-inventory-csv',
    'events.equipment.reports.owner-contacts-pdf',
    'events.equipment.reports.owner-contacts-csv',
    'events.equipment.reports.return-checklist',
    'events.equipment.reports.historical-record',
]);
