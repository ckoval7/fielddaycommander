<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\NetPriority;
use App\Events\NetLogChanged;
use App\Livewire\NetLog\NetForm;
use App\Livewire\NetLog\NetLog;
use App\Models\Band;
use App\Models\EventConfiguration;
use App\Models\Net;
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
    $this->net = Net::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->operator->id,
        'name' => 'Local 2m Net',
        'net_control_station' => 'NCS',
    ]);
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
        ->assertSee('Local 2m Net')
        ->assertSee('NCS: NCS')
        ->assertSee('No net traffic logged yet.')
        ->assertSee('id="exchange-input"', false)
        ->assertSee('enterkeyhint="send"', false)
        ->assertSee('aria-label="Net log entry"', false)
        ->assertSee('x-data="netLogRecall"', false)
        ->assertSee('href="'.route('events.net-log', $this->event).'"', false);
});

test('a line of called station, calling station and message is logged and broadcast', function () {
    EventFacade::fake([NetLogChanged::class]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'ncs aid7 water low at mile 14')
        ->assertHasNoErrors()
        ->assertDispatched('contact-logged')
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
        'net_id' => $this->net->id,
        'user_id' => $this->operator->id,
        'from_station' => 'AID7',
        'to_station' => 'NCS',
        'message' => 'water lo',
        'priority' => NetPriority::Priority,
    ]);

    expect($entry->exchangeLine())->toBe('NCS AID7 PRIORITY water lo');

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSeeHtml('data-recall-value="NCS AID7 PRIORITY water lo"')
        ->call('updateEntry', $entry->id, 'NCS AID7 water low')
        ->assertHasNoErrors();

    expect($entry->fresh())
        ->message->toBe('WATER LOW')
        ->priority->toBe(NetPriority::Routine);
});

test('an operator cannot change someone else\'s entry', function () {
    $entry = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'message' => 'Original']);

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
        'net_id' => $this->net->id,
        'user_id' => $this->operator->id,
        'message' => 'Logged by mistake',
    ]);

    $component = Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('deleteEntry', $entry->id)
        ->assertSee('Logged by mistake')
        ->assertSeeHtml('opacity-40 line-through')
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
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'message' => 'First call', 'logged_at' => now()->subMinutes(10)]);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'message' => 'Latest call', 'logged_at' => now()]);

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
    Net::factory()->create(['event_configuration_id' => $fieldDay->id, 'name' => 'Talk-in']);

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

test('with no nets the page offers to start one and nothing can be logged', function () {
    $this->net->delete();

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSee('No nets yet.')
        ->assertSee('Start net')
        ->assertDontSeeHtml('id="exchange-input"')
        ->call('logEntry', 'NCS AID7 check in')
        ->assertSet('parseError', 'Start a net before logging traffic.');

    expect(NetLogEntry::count())->toBe(0);
});

test('starting a net switches the screen to it and logs onto it', function () {
    EventFacade::fake([NetLogChanged::class]);
    $band = Band::factory()->create(['name' => '80m']);

    Livewire::actingAs($this->operator)->test(NetForm::class, ['event' => $this->event])
        ->dispatch('open-net-form')
        ->assertSet('showNetForm', true)
        ->set('netName', 'Regional HF Net')
        ->set('netFrequency', '3.940 MHz')
        ->set('netBandId', $band->id)
        ->set('netControlStation', 'W1HF')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showNetForm', false)
        ->assertDispatched('net-changed', netId: Net::where('name', 'Regional HF Net')->value('id'));

    $hfNet = Net::where('name', 'Regional HF Net')->sole();

    expect($hfNet->user_id)->toBe($this->operator->id);

    $component = Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->dispatch('net-changed', netId: $hfNet->id)
        ->assertSet('netId', $hfNet->id)
        ->assertSee('3.940 MHz · 80m · NCS: W1HF');

    $component->call('logEntry', 'W1HF K1ABC check in');

    expect(NetLogEntry::sole()->net_id)->toBe($hfNet->id);
    EventFacade::assertDispatched(NetLogChanged::class, fn ($event) => $event->entryId === null);
});

test('a net needs a name', function () {
    Livewire::actingAs($this->operator)->test(NetForm::class, ['event' => $this->event])
        ->call('open')
        ->call('save')
        ->assertHasErrors(['netName' => 'required']);
});

test('each net shows only its own traffic and the URL picks the net', function () {
    $hfNet = Net::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Regional HF Net']);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'message' => 'ON TWO METERS']);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $hfNet->id, 'message' => 'ON EIGHTY METERS']);

    Livewire::withQueryParams(['net' => $this->net->id])
        ->actingAs($this->operator)
        ->test(NetLog::class, ['event' => $this->event])
        ->assertSee('ON TWO METERS')
        ->assertDontSee('ON EIGHTY METERS')
        ->set('netId', $hfNet->id)
        ->assertSee('ON EIGHTY METERS')
        ->assertDontSee('ON TWO METERS');
});

test('without a net in the URL the net with the latest traffic opens', function () {
    $hfNet = Net::factory()->create(['event_configuration_id' => $this->config->id]);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $hfNet->id, 'logged_at' => now()]);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'logged_at' => now()->subHour()]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSet('netId', $hfNet->id);

    $otherEventNet = Net::factory()->create();

    Livewire::withQueryParams(['net' => $otherEventNet->id])
        ->actingAs($this->operator)
        ->test(NetLog::class, ['event' => $this->event])
        ->assertSet('netId', $hfNet->id);
});

test('a multi-word net control station is recognised in a line', function () {
    $this->net->update(['net_control_station' => 'Net Control']);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry', 'net control aid 7 check in');

    expect(NetLogEntry::sole())
        ->to_station->toBe('Net Control')
        ->from_station->toBe('AID')
        ->message->toBe('7 CHECK IN');
});

test('only the net\'s starter or a manager edits it, and only an empty net is deleted', function () {
    $someoneElse = User::factory()->create();
    $someoneElse->givePermissionTo('log-contacts');

    Livewire::actingAs($someoneElse)->test(NetForm::class, ['event' => $this->event])
        ->call('open', $this->net->id)
        ->assertForbidden();

    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id]);

    Livewire::actingAs($this->operator)->test(NetForm::class, ['event' => $this->event])
        ->call('open', $this->net->id)
        ->call('delete')
        ->assertDispatched('toast', title: 'Only an empty net you started can be deleted');

    $planned = Net::factory()->create(['event_configuration_id' => $this->config->id, 'user_id' => $this->operator->id]);

    Livewire::actingAs($this->operator)->test(NetForm::class, ['event' => $this->event])
        ->call('open', $planned->id)
        ->call('delete')
        ->assertDispatched('net-changed', netId: null);

    expect(Net::find($planned->id))->toBeNull()
        ->and(Net::find($this->net->id))->not->toBeNull();

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->dispatch('net-changed', netId: null)
        ->assertSet('netId', $this->net->id);
});

test('nets from another event cannot be touched', function () {
    $other = Net::factory()->create(['user_id' => $this->operator->id]);

    Livewire::actingAs($this->operator)->test(NetForm::class, ['event' => $this->event])
        ->call('open', $other->id)
        ->assertNotFound();
});

test('net control sees each station with its location, operator and last heard', function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->net->update(['overdue_after_minutes' => 30]);
    $aid7 = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'tactical_callsign' => 'Aid 7',
        'location' => 'Mile 12 aid station, Elm St and 3rd',
    ]);
    OperatingSession::factory()->create([
        'station_id' => $aid7->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'W1ABC'])->id,
        'end_time' => null,
        'is_transcription' => false,
    ]);
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 9']);
    NetLogEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'net_id' => $this->net->id,
        'from_station' => 'Aid 9',
        'logged_at' => now()->subMinutes(45),
    ]);
    NetLogEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'net_id' => $this->net->id,
        'from_station' => 'Aid 7',
        'logged_at' => now()->subMinutes(5),
    ]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSeeHtml('x-data="stationLastHeard"')
        ->assertSeeHtml('wire:ignore.self')
        ->assertSeeHtml('x-text="ago($el.dataset.heardAt)"')
        ->assertSeeHtml(':class="{ \'!border-error bg-error/10\': isOverdue($el.dataset.overdueAt) }"')
        ->assertSee('Mile 12 aid station, Elm St and 3rd')
        ->assertSee('W1ABC')
        ->assertSee('5m ago')
        ->assertSee('45m ago')
        ->assertSee('1 overdue')
        ->assertSee('Overdue after 30 min')
        ->assertSee('No operator on duty');
});

test('the station list is left out when no station has a tactical callsign or location', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Station 1']);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertDontSeeHtml('data-station-status');
});

test('logging traffic updates when a station was last heard', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSee('Not heard yet')
        ->call('logEntry', 'NCS Aid 7 all quiet')
        ->assertDontSee('Not heard yet')
        ->assertSee('just now');
});

test('a net\'s overdue threshold is set and cleared in the net form', function () {
    Livewire::actingAs($this->operator)->test(NetForm::class, ['event' => $this->event])
        ->call('open', $this->net->id)
        ->assertSeeHtml('inputmode="numeric"')
        ->set('netOverdueAfterMinutes', 20)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->net->fresh()->overdue_after_minutes)->toBe(20);

    Livewire::actingAs($this->operator)->test(NetForm::class, ['event' => $this->event])
        ->call('open', $this->net->id)
        ->assertSet('netOverdueAfterMinutes', 20)
        ->set('netOverdueAfterMinutes', 0)
        ->call('save')
        ->assertHasErrors(['netOverdueAfterMinutes' => 'min'])
        ->set('netOverdueAfterMinutes', null)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->net->fresh()->overdue_after_minutes)->toBeNull();
});
