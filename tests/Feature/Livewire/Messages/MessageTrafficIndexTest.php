<?php

use App\Livewire\Messages\MessageTrafficIndex;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Message;
use App\Models\User;
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

    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);

    Permission::firstOrCreate(['name' => 'log-contacts']);
    Permission::firstOrCreate(['name' => 'manage-bonuses']);

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

    test('displays ICS-213 messages on index', function () {
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
