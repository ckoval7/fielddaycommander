<?php

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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.175, 'allowed_fd' => true, 'sort_order' => 4]);
    $mode = Mode::firstOrCreate(['name' => 'Phone'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true]);
    $stx = Section::firstOrCreate(['code' => 'STX'], ['name' => 'South Texas', 'region' => 'W5', 'country' => 'US', 'is_active' => true]);

    $event = Event::factory()->create(['start_time' => now()->subHours(2), 'end_time' => now()->addHours(10)]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id, 'section_id' => $stx->id, 'transmitter_count' => 3]);
    $this->station = Station::factory()->create(['event_configuration_id' => $config->id, 'name' => 'Phone 1']);

    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    $session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'power_watts' => 100,
        'is_transcription' => true,
        'start_time' => $event->start_time,
        'end_time' => $event->end_time,
    ]);

    $this->contact = Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'callsign' => 'K1ABC',
        'exchange_class' => '2A',
        'is_transcribed' => true,
        'qso_time' => now()->subMinutes(10),
    ]);
});

test('mobile user can tap a transcribed contact to recall and delete it', function () {
    $this->actingAs($this->user);

    $page = visit('/logging/transcribe/'.$this->station->id)
        ->resize(375, 812);

    $page->assertVisible('button[wire\\:key="card-'.$this->contact->id.'"]')
        ->assertSee('K1ABC');

    $page->assertSourceHas('grid-cols-1 sm:grid-cols-3');

    $page->click('button[wire\\:key="card-'.$this->contact->id.'"]')
        ->waitForText('Editing recalled QSO');

    $page->click('button:has-text("Delete")')
        ->assertDontSee('Editing recalled QSO');

    expect(Contact::onlyTrashed()->where('callsign', 'K1ABC')->count())->toBe(1);
});

test('tablet user can tap a transcribed contact row in the desktop table to recall', function () {
    $this->actingAs($this->user);

    $page = visit('/logging/transcribe/'.$this->station->id)
        ->resize(768, 1024);

    $page->assertVisible('tr[wire\\:key="contact-'.$this->contact->id.'"]')
        ->assertSee('K1ABC');

    $page->click('tr[wire\\:key="contact-'.$this->contact->id.'"]')
        ->waitForText('Editing recalled QSO');

    $page->click('button:has-text("Delete")')
        ->assertDontSee('Editing recalled QSO');

    expect(Contact::onlyTrashed()->where('callsign', 'K1ABC')->count())->toBe(1);
});
