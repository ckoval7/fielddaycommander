<?php

use App\Livewire\Messages\MessageForm;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    Permission::firstOrCreate(['name' => 'log-contacts']);
    Permission::firstOrCreate(['name' => 'manage-bonuses']);

    $this->eventType = EventType::where('code', 'FD')->first();
    $this->event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);

    $this->operator = User::factory()->create(['callsign' => 'W1TEST']);
    $this->operator->givePermissionTo('log-contacts');
});

describe('access control', function () {
    test('requires log-contacts permission', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(MessageForm::class, ['event' => $this->event])
            ->assertForbidden();
    });

    test('allows operators with log-contacts', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->assertOk();
    });
});

describe('creating messages', function () {
    test('can create a radiogram message', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'radiogram')
            ->set('role', 'originated')
            ->set('messageNumber', 1)
            ->set('stationOfOrigin', 'W1TEST')
            ->set('checkCount', '12')
            ->set('placeOfOrigin', 'Hartford, CT')
            ->set('addresseeName', 'John Smith')
            ->set('messageText', 'TEST MESSAGE X HELLO WORLD')
            ->set('signature', 'Jane Doe')
            ->call('save')
            ->assertHasNoErrors();

        expect(Message::where('event_configuration_id', $this->eventConfig->id)->count())->toBe(1);
    });

    test('validates required fields', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('stationOfOrigin', '')
            ->call('save')
            ->assertHasErrors(['messageNumber', 'stationOfOrigin', 'addresseeName', 'messageText', 'signature']);
    });

    test('auto-calculates check from message text', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('messageText', 'HELLO WORLD THIS IS A TEST')
            ->assertSet('checkCount', '6');
    });
});

describe('SM/SEC template', function () {
    test('pre-fills SM message template', function () {
        Livewire::actingAs($this->operator)
            ->withQueryParams(['template' => 'sm'])
            ->test(MessageForm::class, ['event' => $this->event])
            ->assertSet('isSmMessage', true)
            ->assertSet('role', 'originated')
            ->assertSet('precedence', 'routine');
    });

    test('leaves place of origin empty when no city or state configured', function () {
        $this->eventConfig->update(['city' => null, 'state' => null]);

        Livewire::actingAs($this->operator)
            ->withQueryParams(['template' => 'sm'])
            ->test(MessageForm::class, ['event' => $this->event])
            ->assertSet('placeOfOrigin', '')
            ->assertSeeHtml('[CITY STATE]');
    });

    test('auto-populates place of origin from event city and state', function () {
        $this->eventConfig->update(['city' => 'Hartford', 'state' => 'CT']);

        Livewire::actingAs($this->operator)
            ->withQueryParams(['template' => 'sm'])
            ->test(MessageForm::class, ['event' => $this->event])
            ->assertSet('placeOfOrigin', 'Hartford, CT')
            ->assertSeeHtml('LOCATION Hartford, CT X');
    });

    test('auto-populates place of origin with only city when state is absent', function () {
        $this->eventConfig->update(['city' => 'Hartford', 'state' => null]);

        Livewire::actingAs($this->operator)
            ->withQueryParams(['template' => 'sm'])
            ->test(MessageForm::class, ['event' => $this->event])
            ->assertSet('placeOfOrigin', 'Hartford')
            ->assertSeeHtml('LOCATION Hartford X');
    });

    test('prevents duplicate SM message', function () {
        Message::factory()->smMessage()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('isSmMessage', true)
            ->set('messageNumber', 2)
            ->set('stationOfOrigin', 'W1TEST')
            ->set('checkCount', '5')
            ->set('placeOfOrigin', 'Hartford, CT')
            ->set('addresseeName', 'SM Name')
            ->set('messageText', 'TEST')
            ->set('signature', 'Operator')
            ->set('role', 'originated')
            ->set('format', 'radiogram')
            ->call('save')
            ->assertHasErrors(['isSmMessage']);
    });
});

describe('editing messages', function () {
    test('can edit own message', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event, 'message' => $message])
            ->set('addresseeName', 'Updated Name')
            ->call('save')
            ->assertHasNoErrors();

        expect($message->fresh()->addressee_name)->toBe('Updated Name');
    });
});

describe('ICS-213 messages', function () {
    test('can create an ICS-213 message', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'ics213')
            ->set('role', 'originated')
            ->set('messageNumber', 1)
            ->set('addresseeName', 'John Smith')
            ->set('icsToPosition', 'Operations Chief')
            ->set('signature', 'Jane Doe')
            ->set('icsFromPosition', 'Planning Section')
            ->set('icsSubject', 'Resource Request')
            ->set('messageText', 'Need additional radios for sector 5.')
            ->call('save')
            ->assertHasNoErrors();

        $message = Message::where('event_configuration_id', $this->eventConfig->id)->first();
        expect($message->format->value)->toBe('ics213')
            ->and($message->ics_subject)->toBe('Resource Request')
            ->and($message->ics_to_position)->toBe('Operations Chief')
            ->and($message->ics_from_position)->toBe('Planning Section')
            ->and($message->precedence)->toBeNull()
            ->and($message->station_of_origin)->toBeNull()
            ->and($message->check)->toBeNull();
    });

    test('ICS-213 does not require radiogram fields', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'ics213')
            ->set('role', 'originated')
            ->set('messageNumber', 1)
            ->set('addresseeName', 'John Smith')
            ->set('signature', 'Jane Doe')
            ->set('icsSubject', 'Test Subject')
            ->set('messageText', 'Test body')
            ->call('save')
            ->assertHasNoErrors();
    });

    test('ICS-213 requires subject', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'ics213')
            ->set('role', 'originated')
            ->set('messageNumber', 1)
            ->set('addresseeName', 'John Smith')
            ->set('signature', 'Jane Doe')
            ->set('icsSubject', '')
            ->set('messageText', 'Test body')
            ->call('save')
            ->assertHasErrors(['icsSubject']);
    });

    test('ICS-213 message text is not uppercased', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'ics213')
            ->set('role', 'originated')
            ->set('messageNumber', 1)
            ->set('addresseeName', 'John Smith')
            ->set('signature', 'Jane Doe')
            ->set('icsSubject', 'Test')
            ->set('messageText', 'Mixed case message text')
            ->call('save')
            ->assertHasNoErrors();

        $message = Message::where('event_configuration_id', $this->eventConfig->id)->first();
        expect($message->message_text)->toBe('Mixed case message text');
    });

    test('auto word count does not fire for ICS-213', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'ics213')
            ->set('messageText', 'some words here for testing')
            ->assertSet('checkCount', '');
    });

    test('can edit an ICS-213 message', function () {
        $message = Message::factory()->ics213()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'ics_subject' => 'Original Subject',
            'ics_to_position' => 'Ops Chief',
            'ics_from_position' => 'Planning',
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event, 'message' => $message])
            ->assertSet('format', 'ics213')
            ->assertSet('icsSubject', 'Original Subject')
            ->assertSet('icsToPosition', 'Ops Chief')
            ->assertSet('icsFromPosition', 'Planning')
            ->set('icsSubject', 'Updated Subject')
            ->call('save')
            ->assertHasNoErrors();

        expect($message->fresh()->ics_subject)->toBe('Updated Subject');
    });
});

describe('HX value', function () {
    test('saves hx_value with applicable HX codes', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'radiogram')
            ->set('role', 'originated')
            ->set('messageNumber', 1)
            ->set('stationOfOrigin', 'W1TEST')
            ->set('checkCount', '5')
            ->set('placeOfOrigin', 'Hartford, CT')
            ->set('addresseeName', 'John Smith')
            ->set('messageText', 'TEST MESSAGE TEXT HERE MORE')
            ->set('signature', 'Jane Doe')
            ->set('hxCode', 'hxb')
            ->set('hxValue', '3')
            ->call('save')
            ->assertHasNoErrors();

        $message = Message::where('event_configuration_id', $this->eventConfig->id)->first();
        expect($message->hx_code->value)->toBe('hxb')
            ->and($message->hx_value)->toBe('3');
    });

    test('does not save hx_value for HXA', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'radiogram')
            ->set('role', 'originated')
            ->set('messageNumber', 1)
            ->set('stationOfOrigin', 'W1TEST')
            ->set('checkCount', '5')
            ->set('placeOfOrigin', 'Hartford, CT')
            ->set('addresseeName', 'John Smith')
            ->set('messageText', 'TEST MESSAGE TEXT HERE MORE')
            ->set('signature', 'Jane Doe')
            ->set('hxCode', 'hxa')
            ->set('hxValue', 'should be ignored')
            ->call('save')
            ->assertHasNoErrors();

        $message = Message::where('event_configuration_id', $this->eventConfig->id)->first();
        expect($message->hx_code->value)->toBe('hxa')
            ->and($message->hx_value)->toBeNull();
    });

    test('clears hx_value when HX code changes to one that does not use it', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('hxCode', 'hxb')
            ->set('hxValue', '3')
            ->set('hxCode', 'hxa')
            ->assertSet('hxValue', null);
    });

    test('clears hx_value when HX code is removed', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('hxCode', 'hxf')
            ->set('hxValue', '04/15')
            ->set('hxCode', null)
            ->assertSet('hxValue', null);
    });

    test('loads hx_value when editing a message', function () {
        $message = Message::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'hx_code' => 'hxf',
            'hx_value' => '04/15',
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event, 'message' => $message])
            ->assertSet('hxCode', 'hxf')
            ->assertSet('hxValue', '04/15');
    });
});

describe('frequency and mode', function () {
    test('received/delivered message saves frequency and mode_category', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'radiogram')
            ->set('role', 'received_delivered')
            ->set('messageNumber', 1)
            ->set('stationOfOrigin', 'W1TEST')
            ->set('checkCount', '5')
            ->set('placeOfOrigin', 'Hartford, CT')
            ->set('addresseeName', 'John Smith')
            ->set('messageText', 'TEST MESSAGE TEXT HERE MORE')
            ->set('signature', 'Jane Doe')
            ->set('receivedFrom', 'K1ABC')
            ->set('frequency', '7.228')
            ->set('modeCategory', 'Phone')
            ->call('save')
            ->assertHasNoErrors();

        $message = Message::where('event_configuration_id', $this->eventConfig->id)->first();
        expect($message->frequency)->toBe('7.228')
            ->and($message->mode_category)->toBe('Phone');
    });

    test('originated message does not save frequency or mode_category', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'radiogram')
            ->set('role', 'originated')
            ->set('messageNumber', 1)
            ->set('stationOfOrigin', 'W1TEST')
            ->set('checkCount', '5')
            ->set('placeOfOrigin', 'Hartford, CT')
            ->set('addresseeName', 'John Smith')
            ->set('messageText', 'TEST MESSAGE TEXT HERE MORE')
            ->set('signature', 'Jane Doe')
            ->set('frequency', '7.228')
            ->set('modeCategory', 'Phone')
            ->call('save')
            ->assertHasNoErrors();

        $message = Message::where('event_configuration_id', $this->eventConfig->id)->first();
        expect($message->frequency)->toBeNull()
            ->and($message->mode_category)->toBeNull();
    });

    test('editing a received message populates frequency and mode', function () {
        $message = Message::factory()->receivedDelivered()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'frequency' => '14.300',
            'mode_category' => 'Phone',
        ]);

        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event, 'message' => $message])
            ->assertSet('frequency', '14.300')
            ->assertSet('modeCategory', 'Phone');
    });
});

describe('format switching', function () {
    test('switching to ICS-213 clears radiogram fields', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->assertSet('format', 'radiogram')
            ->set('checkCount', '12')
            ->set('placeOfOrigin', 'Hartford, CT')
            ->set('format', 'ics213')
            ->assertSet('checkCount', '')
            ->assertSet('placeOfOrigin', '');
    });

    test('switching to radiogram clears ICS-213 fields and restores station of origin', function () {
        Livewire::actingAs($this->operator)
            ->test(MessageForm::class, ['event' => $this->event])
            ->set('format', 'ics213')
            ->set('icsSubject', 'Some subject')
            ->set('icsToPosition', 'Ops Chief')
            ->set('format', 'radiogram')
            ->assertSet('icsSubject', null)
            ->assertSet('icsToPosition', null)
            ->assertSet('stationOfOrigin', $this->eventConfig->callsign);
    });
});
