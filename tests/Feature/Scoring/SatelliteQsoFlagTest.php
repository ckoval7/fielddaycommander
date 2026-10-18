<?php

use App\DTOs\ExternalContactDto;
use App\Enums\AdifRecordStatus;
use App\Livewire\Logging\TranscribeInterface;
use App\Models\AdifImport;
use App\Models\AdifImportRecord;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\AdifImportService;
use App\Services\ExternalContactHandler;
use App\Services\SessionResolverService;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\OperatingClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    $this->travelTo(now()->setTime(20, 0));

    $this->seed([EventTypeSeeder::class, OperatingClassSeeder::class, BonusTypeSeeder::class]);

    $fdType = EventType::where('code', 'FD')->firstOrFail();

    $this->band = Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'allowed_fd' => true, 'sort_order' => 6]);
    $this->satelliteBand = Band::create(['name' => 'Satellite', 'is_satellite' => true, 'allowed_fd' => true, 'sort_order' => 25]);
    $this->mode = Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->event = Event::factory()->create([
        'event_type_id' => $fdType->id,
        'rules_version' => '2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'operating_class_id' => OperatingClass::where('code', 'A')->where('event_type_id', $fdType->id)->firstOrFail()->id,
        'callsign' => 'W2XYZ',
    ]);

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'HF-PC',
        'is_satellite' => false,
    ]);
    $this->satelliteStation = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'SAT-PC',
        'is_satellite' => true,
    ]);

    $this->user = User::factory()->create(['call_sign' => 'K3CPK']);
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);
});

/**
 * Assert the only contact logged is a satellite QSO that earns the Field Day bonus.
 */
function expectSatelliteQso(EventConfiguration $config): void
{
    $contact = Contact::sole();

    expect($contact->is_satellite)->toBeTrue()
        ->and($config->fresh()->calculateSatelliteBonus())->toBe(100);
}

function sessionOn(Station $station, User $user, Band $band, Mode $mode): OperatingSession
{
    return OperatingSession::factory()->active()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'power_watts' => 100,
    ]);
}

test('browser logging flags a satellite contact', function (string $stationKey, string $bandKey) {
    $session = sessionOn($this->{$stationKey}, $this->user, $this->{$bandKey}, $this->mode);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $session->id,
            'band_id' => $this->{$bandKey}->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertCreated();

    expectSatelliteQso($this->config);
})->with([
    'satellite band' => ['station', 'satelliteBand'],
    'satellite station' => ['satelliteStation', 'band'],
]);

test('transcription flags a satellite contact', function (string $stationKey, string $bandKey) {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->{$stationKey}])
        ->set('selectedBandId', $this->{$bandKey}->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('workingDate', $this->event->start_time->format('Y-m-d'))
        ->set('contactTime', $this->event->start_time->copy()->addHour()->format('H:i'))
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->assertSet('parseError', '');

    expectSatelliteQso($this->config);
})->with([
    'satellite band' => ['station', 'satelliteBand'],
    'satellite station' => ['satelliteStation', 'band'],
]);

test('ADIF import flags a satellite contact', function (string $stationKey, string $bandKey) {
    $import = AdifImport::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
    ]);
    AdifImportRecord::factory()->create([
        'adif_import_id' => $import->id,
        'callsign' => 'W1AW',
        'qso_time' => now()->subHour(),
        'band_id' => $this->{$bandKey}->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'station_id' => $this->{$stationKey}->id,
        'operator_user_id' => $this->user->id,
        'exchange_class' => '3A',
        'status' => AdifRecordStatus::Ready,
    ]);

    (new AdifImportService(new SessionResolverService))->import($import);

    expectSatelliteQso($this->config);
})->with([
    'satellite band' => ['station', 'satelliteBand'],
    'satellite station' => ['satelliteStation', 'band'],
]);

test('external logger flags a satellite contact', function (string $stationName, string $bandName) {
    EventFacade::fake();

    app(ExternalContactHandler::class)->handleContact(new ExternalContactDto(
        callsign: 'W1AW',
        timestamp: now()->subHour(),
        source: 'n1mm',
        bandName: $bandName,
        modeName: 'Phone',
        operatorCallsign: 'K3CPK',
        stationIdentifier: $stationName,
        sectionCode: 'CT',
        externalId: 'sat-qso-1',
    ), $this->config);

    expectSatelliteQso($this->config);
})->with([
    'satellite band' => ['HF-PC', 'Satellite'],
    'satellite station' => ['SAT-PC', '20m'],
]);

test('a contact on a regular band and station is not a satellite QSO', function () {
    $session = sessionOn($this->station, $this->user, $this->band, $this->mode);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertCreated();

    expect(Contact::sole()->is_satellite)->toBeFalse()
        ->and($this->config->fresh()->calculateSatelliteBonus())->toBe(0);
});

test('moving a contact off the Satellite band clears the satellite flag', function () {
    $session = sessionOn($this->station, $this->user, $this->satelliteBand, $this->mode);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $session->id,
        'band_id' => $this->satelliteBand->id,
        'mode_id' => $this->mode->id,
    ]);

    expect($contact->fresh()->is_satellite)->toBeTrue();

    $contact->update(['band_id' => $this->band->id]);

    expect($contact->fresh()->is_satellite)->toBeFalse()
        ->and($this->config->fresh()->calculateSatelliteBonus())->toBe(0);
});

test('backfill migration flags existing contacts on the Satellite band or a Satellite Station', function () {
    $regularSession = sessionOn($this->station, $this->user, $this->band, $this->mode);
    $satelliteSession = sessionOn($this->satelliteStation, $this->user, $this->band, $this->mode);

    $contacts = collect([
        'onSatelliteBand' => [$regularSession, $this->satelliteBand],
        'onSatelliteStation' => [$satelliteSession, $this->band],
        'regular' => [$regularSession, $this->band],
    ])->map(fn (array $pair) => Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $pair[0]->id,
        'band_id' => $pair[1]->id,
        'mode_id' => $this->mode->id,
    ]));

    Contact::query()->update(['is_satellite' => false]);

    $migration = require database_path('migrations/2026_10_17_230002_backfill_satellite_flag_on_contacts.php');
    $migration->up();

    expect($contacts['onSatelliteBand']->fresh()->is_satellite)->toBeTrue()
        ->and($contacts['onSatelliteStation']->fresh()->is_satellite)->toBeTrue()
        ->and($contacts['regular']->fresh()->is_satellite)->toBeFalse();
});
