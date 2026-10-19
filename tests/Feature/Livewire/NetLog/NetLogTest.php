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

test('a line of calling station, your call and message is logged and broadcast', function () {
    EventFacade::fake([NetLogChanged::class]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'aid7 ncs water low at mile 14')
        ->assertHasNoErrors()
        ->assertDispatched('net-log-entry-logged')
        ->assertSet('exchangeInput', '')
        ->assertSee('water low at mile 14');

    $entry = NetLogEntry::sole();

    expect($entry->from_station)->toBe('AID7')
        ->and($entry->to_station)->toBe('NCS')
        ->and($entry->message)->toBe('water low at mile 14')
        ->and($entry->priority)->toBe(NetPriority::Routine)
        ->and($entry->user_id)->toBe($this->operator->id);

    EventFacade::assertDispatched(NetLogChanged::class, fn ($event) => $event->eventId === $this->event->id
        && $event->broadcastAs() === BroadcastChannels::NET_LOG_CHANGED);
});

test('multi-word tactical names are recognised', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Net Control']);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'aid 7 net control runner down');

    expect(NetLogEntry::sole())
        ->from_station->toBe('Aid 7')
        ->to_station->toBe('Net Control')
        ->message->toBe('runner down');
});

test('the priority buttons or a leading EMERGENCY set the priority', function () {
    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('setPriority', 'priority')
        ->call('logEntry', 'AID3 NCS need relief')
        ->assertSet('priority', 'routine')
        ->call('logEntry', 'AID4 NCS emergency runner down');

    $entries = NetLogEntry::orderBy('id')->get();

    expect($entries[0]->priority)->toBe(NetPriority::Priority)
        ->and($entries[1]->priority)->toBe(NetPriority::Emergency)
        ->and($entries[1]->message)->toBe('runner down');
});

test('a line without your call is rejected', function () {
    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'AID7')
        ->assertSet('parseError', fn ($error) => str_starts_with((string) $error, 'Type the calling station'))
        ->assertSee('Type the calling station, then your call');

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

    expect($entry->exchangeLine())->toBe('AID7 NCS PRIORITY water lo');

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSeeHtml('data-exchange="AID7 NCS PRIORITY water lo"')
        ->call('updateEntry', $entry->id, 'AID7 NCS water low')
        ->assertHasNoErrors();

    expect($entry->fresh())
        ->message->toBe('water low')
        ->priority->toBe(NetPriority::Routine);
});

test('an operator cannot change someone else\'s entry', function () {
    $entry = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'message' => 'Original']);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('updateEntry', $entry->id, 'AID1 NCS changed')
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

test('typing suggests tactical names for the calling station, then your call', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Net Control']);

    $component = Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->set('exchangeInput', 'ai');

    expect($component->get('suggestions'))->toBe([['exchange' => 'Aid 7 ', 'worked_on' => 'Nobody on duty']]);

    $component->set('exchangeInput', 'Aid 7 ne');

    expect($component->get('suggestions')[0]['exchange'])->toBe('Aid 7 Net Control ');

    $component->call('selectSuggestion', 'Aid 7 Net Control ')
        ->assertSet('exchangeInput', 'Aid 7 Net Control ')
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
        ->call('logEntry', 'Aid 7 NCS water low')
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
