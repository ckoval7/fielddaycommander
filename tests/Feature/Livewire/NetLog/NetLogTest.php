<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\NetPriority;
use App\Events\NetLogChanged;
use App\Livewire\NetLog\NetLog;
use App\Models\EventConfiguration;
use App\Models\NetLogEntry;
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

test('the net log page renders with mobile-friendly inputs', function () {
    $this->actingAs($this->operator)
        ->get(route('events.net-log', $this->event))
        ->assertOk()
        ->assertSee('Net Log')
        ->assertSee('autocapitalize="characters"', false)
        ->assertSee('No net traffic logged yet')
        ->assertSee('href="'.route('events.net-log', $this->event).'"', false);
});

test('an operator logs an entry and the change is broadcast', function () {
    EventFacade::fake([NetLogChanged::class]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->set('fromStation', 'Aid 7')
        ->set('toStation', 'Net Control')
        ->set('message', 'Runner down at mile 14, requesting medical')
        ->set('priority', NetPriority::Emergency->value)
        ->call('logEntry')
        ->assertHasNoErrors()
        ->assertSet('fromStation', '')
        ->assertSet('priority', 'routine')
        ->assertSee('Runner down at mile 14');

    $entry = NetLogEntry::sole();

    expect($entry->event_configuration_id)->toBe($this->config->id)
        ->and($entry->user_id)->toBe($this->operator->id)
        ->and($entry->priority)->toBe(NetPriority::Emergency)
        ->and($entry->to_station)->toBe('Net Control');

    EventFacade::assertDispatched(NetLogChanged::class, fn ($event) => $event->eventId === $this->event->id
        && $event->broadcastAs() === BroadcastChannels::NET_LOG_CHANGED);
});

test('an entry needs a from station and a message', function () {
    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('logEntry')
        ->assertHasErrors(['fromStation' => 'required', 'message' => 'required']);
});

test('an operator edits their own entry', function () {
    $entry = NetLogEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->operator->id,
        'message' => 'Original',
    ]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('editEntry', $entry->id)
        ->assertSet('showEditModal', true)
        ->set('editMessage', 'Corrected')
        ->set('editPriority', NetPriority::Priority->value)
        ->call('updateEntry')
        ->assertHasNoErrors()
        ->assertSet('showEditModal', false);

    expect($entry->fresh()->message)->toBe('Corrected')
        ->and($entry->fresh()->priority)->toBe(NetPriority::Priority);
});

test('an operator cannot edit or delete someone else\'s entry', function () {
    $entry = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('editEntry', $entry->id)
        ->assertForbidden();

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('deleteEntry', $entry->id)
        ->assertForbidden();
});

test('a manager deletes any entry', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo(['log-contacts', 'verify-bonuses']);
    $entry = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id]);

    Livewire::actingAs($manager)->test(NetLog::class, ['event' => $this->event])
        ->call('deleteEntry', $entry->id);

    expect(NetLogEntry::find($entry->id))->toBeNull()
        ->and(NetLogEntry::withTrashed()->find($entry->id))->not->toBeNull();
});

test('entries from another event cannot be touched', function () {
    $other = NetLogEntry::factory()->create(['user_id' => $this->operator->id]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->call('deleteEntry', $other->id)
        ->assertNotFound();
});

test('entries list newest first', function () {
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'message' => 'First call', 'logged_at' => now()->subMinutes(10)]);
    NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'message' => 'Latest call', 'logged_at' => now()]);

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $this->event])
        ->assertSeeInOrder(['Latest call', 'First call']);
});

test('the net log also works for a Field Day event', function () {
    $fieldDay = EventConfiguration::factory()->create();

    Livewire::actingAs($this->operator)->test(NetLog::class, ['event' => $fieldDay->event])
        ->set('fromStation', 'Talk-in')
        ->set('message', 'Visitor at the gate')
        ->call('logEntry')
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
