<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Messages\MessageTrafficIndex;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    Permission::firstOrCreate(['name' => 'log-contacts']);
    Permission::firstOrCreate(['name' => 'verify-bonuses']);

    $this->eventType = EventType::where('code', 'FD')->first();
    $this->event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);

    $this->operator = User::factory()->create();
    $this->operator->givePermissionTo('log-contacts');
});

describe('listing messages', function () {
    test('displays messages for the event', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'addressee_name' => 'Test Addressee',
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->assertSee('Test Addressee');
    });

    test('displays ICS-213 messages on index when enabled', function () {
        Setting::set('enable_ics213', '1');

        $message = Message::factory()->ics213()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'addressee_name' => 'ICS Recipient',
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->assertSee('ICS Recipient')
            ->assertSee('ICS-213');
    });

    test('hides format column when ICS-213 is disabled', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->assertDontSee('Format');
    });

    test('displays frequency and mode on index', function () {
        Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'frequency' => '7.228',
            'mode_category' => 'Phone',
            'sent_at' => now(),
            'sent_by_user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->assertSee('7.228')
            ->assertSee('Phone');
    });

    test('filters by role', function () {
        Message::factory()->originated()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'addressee_name' => 'Originated Msg',
        ]);
        Message::factory()->relayed()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'addressee_name' => 'Relayed Msg',
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->set('roleFilter', 'originated')
            ->assertSee('Originated Msg')
            ->assertDontSee('Relayed Msg');
    });
});

describe('deleting messages', function () {
    test('operator can delete own message', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('deleteMessage', $message->id);

        expect($message->fresh()->trashed())->toBeTrue();
    });
});

describe('sent tracking', function () {
    test('operator can mark a message as sent', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('markAsSent', $message->id);

        $message->refresh();
        expect($message->sent_at)->not->toBeNull()
            ->and($message->sent_by_user_id)->toBe($this->operator->id);
    });

    test('operator can clear sent status', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'sent_at' => now(),
            'sent_by_user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('unmarkAsSent', $message->id);

        $message->refresh();
        expect($message->sent_at)->toBeNull()
            ->and($message->sent_by_user_id)->toBeNull();
    });

    test('names the clear sent status button for what it does', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'message_number' => 42,
            'sent_at' => now(),
            'sent_by_user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->assertSeeHtml('aria-label="Clear sent status for message 42"');
    });

    test('can change who sent a message', function () {
        $otherOp = User::factory()->create(['call_sign' => 'K2XYZ']);
        $otherOp->givePermissionTo('log-contacts');

        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'sent_at' => now(),
            'sent_by_user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('editSentBy', $message->id)
            ->assertSet('showSentByModal', true)
            ->assertSet('editingSentMessageId', $message->id)
            ->set('selectedSentByUserId', $otherOp->id)
            ->call('saveSentBy');

        $message->refresh();
        expect($message->sent_by_user_id)->toBe($otherOp->id)
            ->and($message->sent_at)->not->toBeNull();
    });

    test('mark sent via modal defaults to current user', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('openSentByModal', $message->id)
            ->assertSet('showSentByModal', true)
            ->assertSet('selectedSentByUserId', $this->operator->id)
            ->call('saveSentBy');

        $message->refresh();
        expect($message->sent_by_user_id)->toBe($this->operator->id)
            ->and($message->sent_at)->not->toBeNull();
    });

    test('saveSentBy saves frequency and mode_category', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('openSentByModal', $message->id)
            ->set('sentFrequency', '7.228')
            ->set('sentModeCategory', 'Phone')
            ->call('saveSentBy');

        $message->refresh();
        expect($message->frequency)->toBe('7.228')
            ->and($message->mode_category)->toBe('Phone');
    });

    test('unmarkAsSent clears frequency and mode_category', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'sent_at' => now(),
            'sent_by_user_id' => $this->operator->id,
            'frequency' => '14.300',
            'mode_category' => 'Phone',
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('unmarkAsSent', $message->id);

        $message->refresh();
        expect($message->sent_at)->toBeNull()
            ->and($message->sent_by_user_id)->toBeNull()
            ->and($message->frequency)->toBeNull()
            ->and($message->mode_category)->toBeNull();
    });

    test('unsent messages show unmarked status badge', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'sent_at' => null,
        ]);

        $html = Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->html();

        expect($html)->toMatch('/Not (sent|delivered)/');
    });

    test('sent messages do not show unmarked status badge', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'sent_at' => now(),
            'sent_by_user_id' => $this->operator->id,
        ]);

        $html = Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->html();

        expect($html)->not->toMatch('/Not (sent|delivered)/');
    });

    test('displays sent status on index', function () {
        $sender = User::factory()->create(['call_sign' => 'N1ABC']);
        $sender->givePermissionTo('log-contacts');

        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'sent_at' => now(),
            'sent_by_user_id' => $sender->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->assertSee('N1ABC');
    });
});

describe('print view', function () {
    test('renders printable radiogram', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'station_of_origin' => 'W1TEST',
            'message_text' => 'HELLO WORLD TEST',
        ]);

        $this->actingAs($this->operator);

        $response = $this->get(route('events.messages.print', [$this->event, $message]));
        $response->assertOk()
            ->assertSee('W1TEST')
            ->assertSee('HELLO WORLD TEST');
    });

    test('renders printable ICS-213', function () {
        $message = Message::factory()->ics213()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'addressee_name' => 'Ops Chief',
            'ics_subject' => 'Resource Request',
            'message_text' => 'Need more radios',
        ]);

        $this->actingAs($this->operator);

        $response = $this->get(route('events.messages.print', [$this->event, $message]));
        $response->assertOk()
            ->assertSee('ICS-213')
            ->assertSee('Resource Request')
            ->assertSee('Need more radios');
    });

    test('renders batch print', function () {
        Message::factory()->count(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        $this->actingAs($this->operator);

        $response = $this->get(route('events.messages.print-all', $this->event));
        $response->assertOk();
    });
});

describe('message bonus scoring by rulebook', function () {
    test('shows bonus point cards for Field Day', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->assertSee('SM/SEC')
            ->assertSee('W1AW')
            ->assertDontSee('does not award message traffic points');
    });

    test('hides bonus point cards but keeps SM/SEC available for Winter Field Day', function () {
        $wfdEvent = Event::factory()->create([
            'event_type_id' => EventType::where('code', 'WFD')->first()->id,
        ]);
        EventConfiguration::factory()->create(['event_id' => $wfdEvent->id]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $wfdEvent])
            ->assertSee('does not award message traffic points')
            ->assertSee('SM/SEC Message')
            ->assertDontSee('W1AW')
            ->assertDontSee(' pts');
    });
});

describe('sent tracking authorization', function () {
    test('operator can mark another operators message as sent', function () {
        $owner = User::factory()->create();
        $owner->givePermissionTo('log-contacts');

        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $owner->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('markAsSent', $message->id)
            ->assertHasNoErrors();

        $message->refresh();
        expect($message->sent_at)->not->toBeNull()
            ->and($message->sent_by_user_id)->toBe($this->operator->id);
    });

    test('operator can clear sent status on another operators message', function () {
        $owner = User::factory()->create();
        $owner->givePermissionTo('log-contacts');

        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $owner->id,
            'sent_at' => now(),
            'sent_by_user_id' => $owner->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('unmarkAsSent', $message->id)
            ->assertHasNoErrors();

        expect($message->refresh()->sent_at)->toBeNull();
    });

    test('operator can set who sent another operators message', function () {
        $owner = User::factory()->create();
        $owner->givePermissionTo('log-contacts');

        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $owner->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('openSentByModal', $message->id)
            ->set('selectedSentByUserId', $owner->id)
            ->call('saveSentBy')
            ->assertHasNoErrors();

        expect($message->refresh()->sent_by_user_id)->toBe($owner->id);
    });

    test('user without log-contacts cannot change sent status', function () {
        $user = User::factory()->create();

        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($user)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('markAsSent', $message->id)
            ->assertForbidden();

        expect($message->refresh()->sent_at)->toBeNull();
    });

    test('cannot change sent status of a message from another event', function () {
        $otherEvent = Event::factory()->create(['event_type_id' => $this->eventType->id]);
        $otherConfig = EventConfiguration::factory()->create(['event_id' => $otherEvent->id]);

        $message = Message::factory()->create([
            'event_configuration_id' => $otherConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('markAsSent', $message->id)
            ->assertNotFound();

        expect($message->refresh()->sent_at)->toBeNull();
    });

    test('saveSentBy cannot target a message from another event', function () {
        $otherEvent = Event::factory()->create(['event_type_id' => $this->eventType->id]);
        $otherConfig = EventConfiguration::factory()->create(['event_id' => $otherEvent->id]);

        $message = Message::factory()->create([
            'event_configuration_id' => $otherConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->set('editingSentMessageId', $message->id)
            ->set('selectedSentByUserId', $this->operator->id)
            ->call('saveSentBy')
            ->assertNotFound();

        expect($message->refresh()->sent_at)->toBeNull();
    });

    test('saveSentBy rejects a nonexistent user', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageTrafficIndex::class, ['event' => $this->event])
            ->call('openSentByModal', $message->id)
            ->set('selectedSentByUserId', 999999)
            ->call('saveSentBy')
            ->assertHasErrors(['selectedSentByUserId']);

        expect($message->refresh()->sent_at)->toBeNull();
    });
});
