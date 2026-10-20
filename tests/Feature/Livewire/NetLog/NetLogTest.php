<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\NetPriority;
use App\Events\NetLogChanged;
use App\Livewire\NetLog\NetLog;
use App\Models\EventConfiguration;
use App\Models\NetLogEntry;
use App\Models\OperatingSession;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use App\Support\BroadcastChannels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Permission::firstOrCreate(['name' => 'log-contacts']);
    Permission::firstOrCreate(['name' => 'verify-bonuses']);

    $this->operator = User::factory()->create(['call_sign' => 'W1NET']);
    $this->operator->givePermissionTo('log-contacts');

    $this->config = EventConfiguration::factory()->publicService()->create();
    $this->event = $this->config->event;
});

test('the net log requires the log-contacts permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('events.net-log', $this->event))
        ->assertForbidden();
});

test('the net log uses the chat-style log window and composer', function () {
    $this->actingAs($this->operator)
        ->get(route('events.net-log', $this->event))
        ->assertOk()
        ->assertSee('Net Traffic')
        ->assertSee('No net traffic logged yet.')
        ->assertSee('id="exchange-input"', false)
        ->assertSee('enterkeyhint="send"', false)
        ->assertSee('aria-label="Net log entry"', false)
        ->assertSee('x-data="netLogComposer()"', false)
        ->assertSee('href="'.route('events.net-log', $this->event).'"', false);
});

test('a line of called station, calling station and message is logged and broadcast', function () {
    EventFacade::fake([NetLogChanged::class]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'ncs aid7 water low at mile 14')
        ->assertHasNoErrors()
        ->assertDispatched('net-log-entry-logged')
        ->assertSet('exchangeInput', '')
        ->assertSee('WATER LOW AT MILE 14')
        ->assertSeeHtmlInOrder(['<th>To</th>', '<th>From</th>']);

    $entry = NetLogEntry::sole();

    expect($entry->from_station)->toBe('AID7')
        ->and($entry->to_station)->toBe('NCS')
        ->and($entry->message)->toBe('WATER LOW AT MILE 14')
        ->and($entry->priority)->toBe(NetPriority::Routine)
        ->and($entry->user_id)->toBe($this->operator->id);

    EventFacade::assertDispatched(NetLogChanged::class, fn ($event) => $event->eventId === $this->event->id
        && $event->broadcastAs() === BroadcastChannels::NET_LOG_CHANGED);
});

test('multi-word tactical names are recognised', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Net Control']);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'net control aid 7 runner down');

    expect(NetLogEntry::sole())
        ->from_station->toBe('Aid 7')
        ->to_station->toBe('Net Control')
        ->message->toBe('RUNNER DOWN');
});

test('the priority buttons or a leading EMERGENCY set the priority', function () {
    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('setPriority', 'priority')
        ->call('logEntry', 'NCS AID3 need relief')
        ->assertSet('priority', 'routine')
        ->call('logEntry', 'NCS AID4 emergency runner down');

    $entries = NetLogEntry::orderBy('id')->get();

    expect($entries[0]->priority)->toBe(NetPriority::Priority)
        ->and($entries[1]->priority)->toBe(NetPriority::Emergency)
        ->and($entries[1]->message)->toBe('RUNNER DOWN');
});

test('a line without the calling station is rejected', function () {
    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'AID7')
        ->assertSet('parseError', fn ($error) => str_starts_with((string) $error, 'Type the station being called'))
        ->assertSee('Type the station being called, then the calling station');

    expect(NetLogEntry::count())->toBe(0);
});

test('a recalled entry is saved from its edited line', function () {
    $entry = NetLogEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->operator->id,
        'from_station' => 'AID7',
        'to_station' => 'NCS',
        'message' => 'water lo',
        'priority' => NetPriority::Priority,
    ]);

    expect($entry->exchangeLine())->toBe('NCS AID7 PRIORITY water lo');

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSeeHtml('data-exchange="NCS AID7 PRIORITY water lo"')
        ->call('updateEntry', $entry->id, 'NCS AID7 water low')
        ->assertHasNoErrors();

    expect($entry->fresh())
        ->message->toBe('WATER LOW')
        ->priority->toBe(NetPriority::Routine);
});

test('an operator cannot change someone else\'s entry', function () {
    $entry = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'message' => 'Original']);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('updateEntry', $entry->id, 'NCS AID1 changed')
        ->assertDispatched('toast', title: 'You can only edit your own entries')
        ->call('deleteEntry', $entry->id)
        ->assertDispatched('toast', title: 'You can only delete your own entries');

    expect($entry->fresh())->message->toBe('Original')->deleted_at->toBeNull();
});

test('a deleted entry stays in the window with an Undo', function () {
    $entry = NetLogEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->operator->id,
        'message' => 'Logged by mistake',
    ]);

    $component = Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('deleteEntry', $entry->id)
        ->assertSee('Logged by mistake')
        ->assertSeeHtml('data-deleted="true"')
        ->assertSee('Undo');

    expect(NetLogEntry::find($entry->id))->toBeNull();

    $component->call('restoreEntry', $entry->id);

    expect(NetLogEntry::find($entry->id))->not->toBeNull();
});

test('a manager deletes any entry', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo(['log-contacts', 'verify-bonuses']);
    $entry = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id]);

    Livewire::actingAs($manager)->test(NetLog::class, ['event' => $this->event])
        ->call('deleteEntry', $entry->id);

    expect(NetLogEntry::find($entry->id))->toBeNull();
});

test('entries from another event cannot be touched', function () {
    $other = NetLogEntry::factory()->create(['user_id' => $this->operator->id]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('deleteEntry', $other->id)
        ->assertNotFound();
});

test('entries list oldest first so the newest sits above the input', function () {
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'message' => 'First call', 'logged_at' => now()->subMinutes(10)]);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'message' => 'Latest call', 'logged_at' => now()]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSeeInOrder(['First call', 'Latest call']);
});

test('typing suggests tactical names for the called station, then the calling station', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Net Control']);

    $component = Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->set('exchangeInput', 'ne');

    expect($component->get('suggestions'))->toBe([['exchange' => 'Net Control ', 'worked_on' => 'Nobody on duty']]);

    $component->set('exchangeInput', 'Net Control ai');

    expect($component->get('suggestions')[0]['exchange'])->toBe('Net Control Aid 7 ');

    $component->call('selectSuggestion', 'Net Control Aid 7 ')
        ->assertSet('exchangeInput', 'Net Control Aid 7 ')
        ->assertDispatched('suggestion-selected');
});

test('entries snapshot the operator behind each tactical callsign', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'W1ABC'])->id,
        'end_time' => null,
        'is_transcription' => false,
    ]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'NCS Aid 7 water low')
        ->assertSee('Aid 7 (W1ABC)');

    expect(NetLogEntry::sole()->from_callsign)->toBe('W1ABC')
        ->and(NetLogEntry::sole()->to_callsign)->toBeNull();
});

test('the net log also works for a Field Day event', function () {
    $fieldDay = EventConfiguration::factory()->create();

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $fieldDay->event])
        ->call('logEntry', 'TALKIN W1AW visitor at the gate')
        ->assertHasNoErrors();

    expect(NetLogEntry::sole()->event_configuration_id)->toBe($fieldDay->id);
});

test('the net log listens for live changes on the event channel', function () {
    $listeners = Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->instance()->getListeners();

    expect($listeners)->toHaveKey('echo-private:event.'.$this->event->id.',.'.BroadcastChannels::NET_LOG_CHANGED);
});

test('a station shows its operator callsign when it has one', function () {
    expect(NetLogEntry::displayStation('Aid 7', 'W1ABC'))->toBe('Aid 7 (W1ABC)')
        ->and(NetLogEntry::displayStation('W1ABC', 'W1ABC'))->toBe('W1ABC')
        ->and(NetLogEntry::displayStation('Aid 7', null))->toBe('Aid 7')
        ->and(NetLogEntry::displayStation(null, null))->toBe('');
});
