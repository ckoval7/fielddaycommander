<?php

use App\Livewire\Messages\MessageTrafficIndex;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Message;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
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
