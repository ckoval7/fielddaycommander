<?php

use App\Livewire\Admin\AdifImport;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'sort_order' => 3]);
    Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    Mode::create(['name' => 'Digital', 'category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);
    Section::create(['code' => 'NNJ', 'name' => 'Northern New Jersey', 'region' => 'W2', 'is_active' => true]);

    $eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day', 'is_active' => true],
    );

    OperatingClass::firstOrCreate(
        ['event_type_id' => $eventType->id, 'code' => 'A'],
        ['name' => 'Class A', 'description' => 'Class A', 'allows_gota' => false, 'allows_free_vhf' => false, 'max_power_watts' => 1500, 'requires_emergency_power' => false],
    );

    OperatingClass::firstOrCreate(
        ['event_type_id' => $eventType->id, 'code' => 'B'],
        ['name' => 'Class B', 'description' => 'Class B', 'allows_gota' => false, 'allows_free_vhf' => false, 'max_power_watts' => 1500, 'requires_emergency_power' => false],
    );

    $this->event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'DESKTOP-11-2024',
    ]);

    $this->user = User::factory()->create(['call_sign' => 'K3CPK']);
    $permission = Permission::firstOrCreate(['name' => 'import-contacts']);
    $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $this->user->assignRole($role);

    // Set event context (key matches EventContextService::SESSION_KEY = 'viewing_event_id')
    session(['viewing_event_id' => $this->event->id]);
});

test('non-admin cannot access import page', function () {
    $regularUser = User::factory()->create();

    $this->actingAs($regularUser)
        ->get(route('admin.import-adif'))
        ->assertForbidden();
});

test('admin can access import page', function () {
    $this->actingAs($this->user)
        ->get(route('admin.import-adif'))
        ->assertOk();
});

test('can upload and parse an ADIF file', function () {
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>20260410 <TIME_ON:6>005505 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>\n <CALL:5>W2WSX <QSO_DATE:8>20260410 <TIME_ON:6>005853 <ARRL_SECT:3>NNJ <BAND:3>40M <STATION_CALLSIGN:5>K3CPK <MODE:4>RTTY <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>2B <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>";

    $file = UploadedFile::fake()->createWithContent('test.adi', $adifContent);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', $file)
        ->call('uploadFile')
        ->assertSet('step', 2);
});

test('full import flow creates contacts', function () {
    $qsoDate = now()->format('Ymd');
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>{$qsoDate} <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>";

    $file = UploadedFile::fake()->createWithContent('test.adi', $adifContent);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', $file)
        ->call('uploadFile')
        ->call('applyMappingsAndContinue')
        ->assertSet('step', 3)
        ->call('executeImport')
        ->assertSet('importStatus', 'completed');

    expect(Contact::where('callsign', 'W1AW')->exists())->toBeTrue();
});
