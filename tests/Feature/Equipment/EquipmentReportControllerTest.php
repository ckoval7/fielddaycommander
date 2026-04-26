<?php

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Models\User;
use App\Services\EquipmentReportService;
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
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
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
