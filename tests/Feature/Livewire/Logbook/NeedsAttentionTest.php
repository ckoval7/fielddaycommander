<?php

use App\Livewire\Logbook\ContactEditor;
use App\Livewire\Logbook\LogbookBrowser;
use App\Livewire\Reports\ReportsIndex;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoint;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    DB::table('system_config')->insertOrIgnore(['key' => 'setup_completed', 'value' => 'true']);

    Permission::findOrCreate('edit-contacts', 'web');
    Permission::findOrCreate('view-reports', 'web');

    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['edit-contacts', 'view-reports']);
    $this->actingAs($this->user);

    $fieldDay = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);

    $this->event = Event::factory()->create([
        'event_type_id' => $fieldDay->id,
        'rules_version' => '2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'is_gota' => false]);

    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.175, 'allowed_fd' => true, 'sort_order' => 4]);
    $this->cw = Mode::firstOrCreate(['name' => 'CW'], ['category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true]);

    ModeRulePoint::create(['event_type_id' => $fieldDay->id, 'rules_version' => '2025', 'mode_id' => $this->cw->id, 'points' => 4]);

    $this->session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->cw->id,
    ]);

    $this->makeContact = fn (array $overrides = []): Contact => Contact::factory()->create(array_merge([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->cw->id,
        'section_id' => $this->section->id,
        'exchange_class' => '1A',
        'qso_time' => now()->subHour(),
        'is_duplicate' => false,
        'is_gota_contact' => false,
    ], $overrides));
});

test('needsAttention scope returns contacts missing band or mode or outside the window', function () {
    $missingBand = ($this->makeContact)(['callsign' => 'K1BND', 'band_id' => null, 'points' => 0]);
    $missingMode = ($this->makeContact)(['callsign' => 'K1MOD', 'mode_id' => null, 'points' => 0]);
    $tooEarly = ($this->makeContact)(['callsign' => 'K1EAR', 'qso_time' => now()->subDays(2), 'points' => 0]);
    $tooLate = ($this->makeContact)(['callsign' => 'K1LAT', 'qso_time' => now()->addDays(2), 'points' => 0]);
    ($this->makeContact)(['callsign' => 'K1OK']);
    ($this->makeContact)(['callsign' => 'K1DEL', 'band_id' => null])->delete();

    $ids = Contact::query()->needsAttention()->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$missingBand, $missingMode, $tooEarly, $tooLate])->pluck('id')->sort()->values()->all());
});

test('attentionReasons lists each problem', function () {
    $contact = ($this->makeContact)(['band_id' => null, 'mode_id' => null, 'qso_time' => now()->subDays(2)]);

    expect($contact->attentionReasons())->toBe(['Missing band', 'Missing mode', 'Outside event window'])
        ->and(($this->makeContact)()->attentionReasons())->toBe([]);
});

test('logbook needs attention filter shows only flagged contacts with their reasons', function () {
    ($this->makeContact)(['callsign' => 'K1BND', 'band_id' => null, 'points' => 0]);
    ($this->makeContact)(['callsign' => 'K1EAR', 'qso_time' => now()->subDays(2), 'points' => 0]);
    ($this->makeContact)(['callsign' => 'K1OK']);

    Livewire::test(LogbookBrowser::class)
        ->set('eventConfigurationId', $this->config->id)
        ->set('needsAttention', 'only')
        ->assertSee('K1BND')
        ->assertSee('K1EAR')
        ->assertSee('Missing band')
        ->assertSee('Outside event window')
        ->assertDontSee('K1OK');
});

test('logbook needs attention filter is bound to the query string', function () {
    ($this->makeContact)(['callsign' => 'K1MOD', 'mode_id' => null, 'points' => 0]);
    ($this->makeContact)(['callsign' => 'K1OK']);

    Livewire::withQueryParams(['needsAttention' => 'only'])
        ->test(LogbookBrowser::class)
        ->assertSet('needsAttention', 'only')
        ->assertSee('K1MOD')
        ->assertSee('Missing mode')
        ->assertDontSee('K1OK');
});

test('reports page warns with a count and logbook link when contacts need attention', function () {
    ($this->makeContact)(['callsign' => 'K1BND', 'band_id' => null, 'points' => 0]);
    ($this->makeContact)(['callsign' => 'K1MOD', 'mode_id' => null, 'points' => 0]);
    ($this->makeContact)(['callsign' => 'K1OK']);

    Livewire::test(ReportsIndex::class)
        ->assertSee('2 contacts need attention.')
        ->assertSee(e(route('logbook.index', ['needsAttention' => 'only', 'event' => $this->event->id])), false);
});

test('the logbook opens the event a link names, with its flagged contacts and reasons', function () {
    $pastEvent = Event::factory()->create([
        'event_type_id' => $this->event->event_type_id,
        'rules_version' => '2025',
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);
    $pastConfig = EventConfiguration::factory()->create(['event_id' => $pastEvent->id]);
    ($this->makeContact)(['callsign' => 'K1PST', 'event_configuration_id' => $pastConfig->id, 'band_id' => null, 'qso_time' => now()->subDays(30)->addHour(), 'points' => 0]);
    ($this->makeContact)(['callsign' => 'K1CUR', 'band_id' => null, 'points' => 0]);

    Livewire::withQueryParams(['needsAttention' => 'only', 'event' => $pastEvent->id])
        ->test(LogbookBrowser::class)
        ->assertSet('eventConfigurationId', $pastConfig->id)
        ->assertSee('K1PST')
        ->assertSee('Missing band')
        ->assertDontSee('K1CUR');
});

test('reports page shows no warning when every contact is complete', function () {
    ($this->makeContact)(['callsign' => 'K1OK']);

    Livewire::test(ReportsIndex::class)
        ->assertDontSee('need attention')
        ->assertDontSee('needs attention')
        ->assertDontSee('needsAttention=only', false);
});

test('fixing band and mode in the contact editor removes it from the pool and prices it', function () {
    $contact = ($this->makeContact)(['callsign' => 'K1FIX', 'band_id' => null, 'mode_id' => null, 'points' => 0]);

    expect(Contact::query()->needsAttention()->count())->toBe(1);

    Livewire::test(ContactEditor::class)
        ->call('openEdit', $contact->id)
        ->set('bandId', $this->band->id)
        ->set('modeId', $this->cw->id)
        ->call('save')
        ->assertHasNoErrors();

    $contact->refresh();

    expect(Contact::query()->needsAttention()->count())->toBe(0)
        ->and($contact->points)->toBe(4)
        ->and($contact->is_duplicate)->toBeFalse();
});
