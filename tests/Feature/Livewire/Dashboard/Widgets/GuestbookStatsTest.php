<?php

use App\Livewire\Dashboard\Widgets\GuestbookStats;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\GuestbookEntry;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);
});

// ============================================================================
// GUESTBOOK STATS WIDGET TESTS
// ============================================================================

describe('GuestbookStats Widget', function () {
    test('component renders successfully without permission', function () {
        Livewire::test(GuestbookStats::class)
            ->assertOk();
    });

    test('component renders successfully with permission', function () {
        $user = User::factory()->create();
        Permission::findOrCreate('manage-guestbook', 'web');
        $user->givePermissionTo('manage-guestbook');

        $this->actingAs($user);

        Livewire::test(GuestbookStats::class)
            ->assertOk();
    });

    test('component mounts with tvMode parameter', function () {
        Livewire::test(GuestbookStats::class, ['tvMode' => true])
            ->assertSet('tvMode', true);

        Livewire::test(GuestbookStats::class, ['tvMode' => false])
            ->assertSet('tvMode', false);
    });

    test('hasPermission is false without manage-guestbook permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->hasPermission)->toBeFalse();
    });

    test('hasPermission is true with manage-guestbook permission', function () {
        $user = User::factory()->create();
        Permission::findOrCreate('manage-guestbook', 'web');
        $user->givePermissionTo('manage-guestbook');

        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->hasPermission)->toBeTrue();
    });

    test('event is null without permission even when active event exists', function () {
        Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->event)->toBeNull();
    });

    test('event is loaded with permission when active event exists', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('manage-guestbook', 'web');
        $user->givePermissionTo('manage-guestbook');

        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->event)->not->toBeNull();
        expect($component->event->id)->toBe($event->id);
    });

    test('totalVisitors returns zero without permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->totalVisitors)->toBe(0);
    });

    test('totalVisitors returns zero when event has no configuration', function () {
        Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('manage-guestbook', 'web');
        $user->givePermissionTo('manage-guestbook');

        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->totalVisitors)->toBe(0);
    });

    test('totalVisitors returns correct count with permission and active event', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        GuestbookEntry::factory()->count(5)->create([
            'event_configuration_id' => $config->id,
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('manage-guestbook', 'web');
        $user->givePermissionTo('manage-guestbook');

        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->totalVisitors)->toBe(5);
    });

    test('inPersonVisitors returns zero without permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->inPersonVisitors)->toBe(0);
    });

    test('inPersonVisitors counts only in-person entries', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        GuestbookEntry::factory()->count(3)->inPerson()->create([
            'event_configuration_id' => $config->id,
        ]);

        GuestbookEntry::factory()->count(2)->online()->create([
            'event_configuration_id' => $config->id,
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('manage-guestbook', 'web');
        $user->givePermissionTo('manage-guestbook');

        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->inPersonVisitors)->toBe(3);
    });

    test('onlineVisitors returns zero without permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->onlineVisitors)->toBe(0);
    });

    test('onlineVisitors counts only online entries', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        GuestbookEntry::factory()->count(2)->inPerson()->create([
            'event_configuration_id' => $config->id,
        ]);

        GuestbookEntry::factory()->count(4)->online()->create([
            'event_configuration_id' => $config->id,
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('manage-guestbook', 'web');
        $user->givePermissionTo('manage-guestbook');

        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->onlineVisitors)->toBe(4);
    });

    test('vipVisitors returns zero without permission', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->vipVisitors)->toBe(0);
    });

    test('vipVisitors counts elected officials and ARRL officials', function () {
        $event = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => appNow()->subHours(12),
            'end_time' => appNow()->addHours(12),
        ]);

        $config = EventConfiguration::factory()->create([
            'event_id' => $event->id,
        ]);

        GuestbookEntry::factory()->count(2)->electedOfficial()->create([
            'event_configuration_id' => $config->id,
        ]);

        GuestbookEntry::factory()->count(1)->arrlOfficial()->create([
            'event_configuration_id' => $config->id,
        ]);

        // Non-VIP visitors
        GuestbookEntry::factory()->count(3)->agency()->create([
            'event_configuration_id' => $config->id,
        ]);

        $user = User::factory()->create();
        Permission::findOrCreate('manage-guestbook', 'web');
        $user->givePermissionTo('manage-guestbook');

        $this->actingAs($user);

        $component = Livewire::test(GuestbookStats::class);

        expect($component->vipVisitors)->toBe(3);
    });
});
