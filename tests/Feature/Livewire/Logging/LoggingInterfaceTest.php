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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    \Illuminate\Support\Facades\DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    // Register the route temporarily for testing
    Route::middleware(['web', 'auth'])->get('/logging/session/{operatingSession}', LoggingInterface::class)->name('logging.session');

    // Create reference data
    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);

    $this->phoneMode = Mode::where('name', 'Phone')->first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);

    $this->cwMode = Mode::where('name', 'CW')->first() ?? Mode::create([
        'name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2,
    ]);

    $this->section = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->stxSection = Section::firstOrCreate(
        ['code' => 'STX'],
        ['name' => 'South Texas', 'region' => 'W5', 'country' => 'US', 'is_active' => true],
    );

    // Create active event
    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'section_id' => $this->stxSection->id,
        'transmitter_count' => 3,
    ]);

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'Phone 1',
    ]);

    // Create user with permission
    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    // Create active session
    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);
});

test('unauthenticated user is redirected', function () {
    $this->get("/logging/session/{$this->session->id}")
        ->assertRedirect();
});

test('user cannot access another users session', function () {
    $otherUser = User::factory()->create();
    $otherUser->givePermissionTo('log-contacts');

    $this->actingAs($otherUser);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertForbidden();
});

test('ended session redirects to station select', function () {
    $this->actingAs($this->user);

    $endedSession = OperatingSession::factory()->ended()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $endedSession])
        ->assertRedirect(route('logging.station-select'));
});

test('session info displays correctly', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertStatus(200)
        ->assertSee('Phone 1')
        ->assertSee('20m')
        ->assertSee('Phone')
        ->assertSee('100W');
});

test('club exchange is displayed with callsign', function () {
    $this->actingAs($this->user);

    $callsign = $this->config->callsign;
    $classCode = $this->config->operatingClass->code ?? '?';
    $expectedExchange = "{$callsign} 3{$classCode} STX";

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSee($expectedExchange);
});

test('phonetic exchange is displayed', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSee('Sierra Tango X-ray');
});

test('duplicate warning appears for dupe callsign', function () {
    $this->actingAs($this->user);

    // Create an existing contact for this callsign/band/mode/config
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'W1AW',
        'is_duplicate' => false,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W1AW 3A CT')
        ->assertSet('isDuplicate', true)
        ->assertSee('W1AW already worked on 20m Phone');
});

test('clear input resets fields', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W1AW 3A CT')
        ->call('clearInput')
        ->assertSet('exchangeInput', '')
        ->assertSet('isDuplicate', false)
        ->assertSet('dupeWarning', '')
        ->assertSet('parseError', '');
});

test('recent contacts shows session contacts only', function () {
    $this->actingAs($this->user);

    // Create a contact for this session
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'K5ABC',
        'qso_time' => now(),
    ]);

    // Create another session and a contact for it
    $otherSession = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => User::factory()->create()->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $otherSession->id,
        'logger_user_id' => $otherSession->operator_user_id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'N0OTHER',
        'qso_time' => now(),
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertSee('K5ABC')
        ->assertDontSee('N0OTHER');
});

test('recent contacts ordered newest first', function () {
    $this->actingAs($this->user);

    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'K5OLD',
        'qso_time' => now()->subMinutes(10),
        'received_exchange' => '1D STX',
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'W1NEW',
        'qso_time' => now(),
        'received_exchange' => '3A CT',
    ]);

    // The newest contact (W1NEW) should appear before the older one (K5OLD)
    // in the rendered HTML
    $html = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->html();

    $posNew = strpos($html, 'W1NEW');
    $posOld = strpos($html, 'K5OLD');

    expect($posNew)->toBeLessThan($posOld);
});

test('end session sets end time and redirects', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->call('endSession')
        ->assertRedirect(route('logging.station-select'));

    $this->session->refresh();
    expect($this->session->end_time)->not->toBeNull();
});

test('cw mode points are assigned by API on sync', function () {
    $this->actingAs($this->user);

    // Create a CW session
    $cwSession = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->cwMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);

    // Sync a CW contact via API to verify points
    $this->postJson('/logging/contacts', [
        'uuid' => fake()->uuid(),
        'operating_session_id' => $cwSession->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->cwMode->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'received_exchange' => 'W1AW 3A CT',
        'power_watts' => 100,
        'qso_time' => now()->toISOString(),
    ])->assertCreated();

    $contact = Contact::where('callsign', 'W1AW')->first();
    expect($contact->points)->toBe(2);
});

test('phone mode points are assigned by API on sync', function () {
    $this->actingAs($this->user);

    $this->postJson('/logging/contacts', [
        'uuid' => fake()->uuid(),
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'received_exchange' => 'W1AW 3A CT',
        'power_watts' => 100,
        'qso_time' => now()->toISOString(),
    ])->assertCreated();

    $contact = Contact::where('callsign', 'W1AW')->first();
    expect($contact->points)->toBe(1);
});

test('suggestions appear for callsign worked on different band', function () {
    $this->actingAs($this->user);

    // Create a second band for the existing contact
    $otherBand = Band::where('name', '!=', '20m')->first() ?? Band::create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.150,
        'allowed_fd' => true, 'sort_order' => 5,
    ]);

    // W1AW was worked on 40m Phone (different band from our 20m session)
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $otherBand->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'W1AW',
        'received_exchange' => 'W1AW 3A CT',
        'is_duplicate' => false,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W1')
        ->assertCount('suggestions', 1)
        ->assertSee('W1AW 3A CT');
});

test('suggestions do not include callsigns already worked on current band and mode', function () {
    $this->actingAs($this->user);

    // W1AW already worked on 20m Phone (same as current session) = dupe, not a suggestion
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'W1AW',
        'received_exchange' => 'W1AW 3A CT',
        'is_duplicate' => false,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W1')
        ->assertCount('suggestions', 0);
});

test('suggestions clear after typing a space', function () {
    $this->actingAs($this->user);

    $otherBand = Band::where('name', '!=', '20m')->first() ?? Band::create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.150,
        'allowed_fd' => true, 'sort_order' => 5,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $otherBand->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'W1AW',
        'received_exchange' => 'W1AW 3A CT',
        'is_duplicate' => false,
    ]);

    // Typing callsign shows suggestions
    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'W1')
        ->assertCount('suggestions', 1)
        // Adding a space (moving to class/section) clears them
        ->set('exchangeInput', 'W1AW ')
        ->assertCount('suggestions', 0);
});

test('selectSuggestion fills full exchange and clears suggestions', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->call('selectSuggestion', 'W1AW 3A CT')
        ->assertSet('exchangeInput', 'W1AW 3A CT')
        ->assertCount('suggestions', 0)
        ->assertDispatched('suggestion-selected');
});

test('contact-synced event refreshes recent contacts list', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertDontSee('K5NEW');

    // Simulate a contact being synced via the API (as the JS queue does)
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'K5NEW',
        'qso_time' => now(),
    ]);

    $this->session->update(['qso_count' => 1]);

    // Fire the Livewire event that the JS queue dispatches after sync
    $component->dispatch('contact-synced')
        ->assertSee('K5NEW');
});

test('GOTA station shows operator fields', function () {
    $this->actingAs($this->user);

    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $gotaSession])
        ->assertSee('GOTA Operator')
        ->assertSee('First Name')
        ->assertSee('Last Name');
});

test('non-GOTA station does not show operator fields', function () {
    $this->actingAs($this->user);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->assertDontSee('GOTA Operator');
});

test('GOTA station uses gota_callsign in exchange', function () {
    $this->actingAs($this->user);

    $this->config->update(['gota_callsign' => 'W5GOT']);

    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);

    $classCode = $this->config->operatingClass->code ?? '?';
    $expectedExchange = "W5GOT {$this->config->transmitter_count}{$classCode} STX";

    Livewire::test(LoggingInterface::class, ['operatingSession' => $gotaSession])
        ->assertSee($expectedExchange);
});

test('selectGotaUser fills operator fields from user record', function () {
    $this->actingAs($this->user);

    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);

    $gotaOperator = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'call_sign' => 'KD5TST',
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $gotaSession])
        ->call('selectGotaUser', $gotaOperator->id)
        ->assertSet('gotaOperatorUserId', $gotaOperator->id)
        ->assertSet('gotaOperatorFirstName', 'Jane')
        ->assertSet('gotaOperatorLastName', 'Doe')
        ->assertSet('gotaOperatorCallsign', 'KD5TST');
});

test('clearGotaUser resets all operator fields', function () {
    $this->actingAs($this->user);

    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);

    $gotaOperator = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'call_sign' => 'KD5TST',
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $gotaSession])
        ->call('selectGotaUser', $gotaOperator->id)
        ->call('clearGotaUser')
        ->assertSet('gotaOperatorUserId', null)
        ->assertSet('gotaOperatorFirstName', '')
        ->assertSet('gotaOperatorLastName', '')
        ->assertSet('gotaOperatorCallsign', '');
});

test('gotaUserResults returns matching users', function () {
    $this->actingAs($this->user);

    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);

    User::factory()->create([
        'first_name' => 'Unique',
        'last_name' => 'SearchTarget',
        'call_sign' => 'KD5UNQ',
    ]);

    $component = Livewire::test(LoggingInterface::class, ['operatingSession' => $gotaSession])
        ->set('gotaUserSearch', 'SearchTarget');

    $results = $component->get('gotaUserResults');
    expect($results)->toHaveCount(1)
        ->and($results[0]['call_sign'])->toBe('KD5UNQ');
});

test('gotaUserResults returns empty for short search', function () {
    $this->actingAs($this->user);

    $gotaStation = Station::factory()->gota()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->phoneMode->id,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);

    $component = Livewire::test(LoggingInterface::class, ['operatingSession' => $gotaSession])
        ->set('gotaUserSearch', 'K');

    $results = $component->get('gotaUserResults');
    expect($results)->toBeEmpty();
});

test('suggestions show full exchange and worked-on bands', function () {
    $this->actingAs($this->user);

    $otherBand = Band::where('name', '!=', '20m')->first() ?? Band::create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.150,
        'allowed_fd' => true, 'sort_order' => 5,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $otherBand->id,
        'mode_id' => $this->phoneMode->id,
        'callsign' => 'K5ABC',
        'received_exchange' => 'K5ABC 1D STX',
        'is_duplicate' => false,
    ]);

    Livewire::test(LoggingInterface::class, ['operatingSession' => $this->session])
        ->set('exchangeInput', 'K5')
        ->assertSee('K5ABC 1D STX')
        ->assertSee('40m Phone');
});
