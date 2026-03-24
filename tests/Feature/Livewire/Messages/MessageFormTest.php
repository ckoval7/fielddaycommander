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
            ->test(MessageForm::class, ['event' => $this->event, 'template' => 'sm'])
            ->assertSet('isSmMessage', true)
            ->assertSet('role', 'originated')
            ->assertSet('precedence', 'routine');
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
