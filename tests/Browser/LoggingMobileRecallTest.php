<?php

use App\Livewire\Logging\LoggingInterface;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    Route::middleware(['web', 'auth'])->get('/logging/session/{operatingSession}', LoggingInterface::class)->name('logging.session');

    $band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.175, 'allowed_fd' => true, 'sort_order' => 4]);
    $mode = Mode::firstOrCreate(['name' => 'Phone'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true]);
    $stx = Section::firstOrCreate(['code' => 'STX'], ['name' => 'South Texas', 'region' => 'W5', 'country' => 'US', 'is_active' => true]);

    $event = Event::factory()->create(['start_time' => now()->subHours(2), 'end_time' => now()->addHours(10)]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id, 'section_id' => $stx->id, 'transmitter_count' => 3]);
    $station = Station::factory()->create(['event_configuration_id' => $config->id, 'name' => 'Phone 1']);

    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'power_watts' => 100,
    ]);

    Contact::factory()->create([
        'operating_session_id' => $this->session->id,
        'event_configuration_id' => $config->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
        'qso_time' => now(),
    ]);
});

test('mobile user can tap a QSO card to enter recall mode and delete it', function () {
    $this->actingAs($this->user);

    $page = visit('/logging/session/'.$this->session->id)
        ->resize(375, 812);

    $contactId = Contact::first()->id;

    $page->assertVisible('button[wire\\:key="card-'.$contactId.'"]')
        ->assertSee('W1AW');

    $page->click('button[wire\\:key="card-'.$contactId.'"]')
        ->waitForText('Editing recalled QSO');

    $page->assertSee('Save')
        ->assertSee('Delete')
        ->assertSee('Cancel');

    $page->click('button:has-text("Delete")')
        ->assertDontSee('Editing recalled QSO');

    expect(Contact::onlyTrashed()->where('callsign', 'W1AW')->count())->toBe(1);
});

test('tablet user can tap a QSO row in the desktop table to enter recall mode', function () {
    $this->actingAs($this->user);

    $page = visit('/logging/session/'.$this->session->id)
        ->resize(768, 1024);

    $contactId = Contact::first()->id;

    $page->assertVisible('tr[wire\\:key="contact-'.$contactId.'"]')
        ->assertSee('W1AW');

    $page->click('tr[wire\\:key="contact-'.$contactId.'"]')
        ->waitForText('Editing recalled QSO');

    $page->assertSee('Save')
        ->assertSee('Delete')
        ->assertSee('Cancel');

    $page->click('button:has-text("Delete")')
        ->assertDontSee('Editing recalled QSO');

    expect(Contact::onlyTrashed()->where('callsign', 'W1AW')->count())->toBe(1);
});
