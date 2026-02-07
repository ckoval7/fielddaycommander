<?php

use App\Livewire\Guestbook\BonusPointsSidebar;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    actingAs($this->user);

    $this->event = Event::factory()->create();
    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'guestbook_enabled' => true,
    ]);
});

test('component renders successfully', function () {
    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertOk()
        ->assertSee('PR Bonus Points');
});

test('displays correct elected official count', function () {
    GuestbookEntry::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('Elected Officials')
        ->assertSee('3');
});

test('displays correct arrl official count', function () {
    GuestbookEntry::factory()->count(2)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('ARRL Officials')
        ->assertSee('2');
});

test('displays correct agency count', function () {
    GuestbookEntry::factory()->count(4)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_AGENCY,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('Agency')
        ->assertSee('4');
});

test('displays correct media count', function () {
    GuestbookEntry::factory()->count(5)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('Media')
        ->assertSee('5');
});

test('calculates total bonus eligible visitors correctly', function () {
    GuestbookEntry::factory()->count(2)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    GuestbookEntry::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    // These should not be counted (not bonus eligible)
    GuestbookEntry::factory()->count(5)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('5 / 10');
});

test('only counts verified entries', function () {
    // Verified
    GuestbookEntry::factory()->count(2)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    // Unverified
    GuestbookEntry::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => false,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('2 / 10');
});

test('calculates bonus points correctly under max', function () {
    GuestbookEntry::factory()->count(7)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('700');
});

test('caps bonus points at 1000 when max is reached', function () {
    GuestbookEntry::factory()->count(15)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('1000')
        ->assertSee('Maximum PR bonus points achieved!');
});

test('displays correct progress message when not at max', function () {
    GuestbookEntry::factory()->count(6)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('4 more needed for max bonus');
});

test('displays max bonus reached message when at 10 visitors', function () {
    GuestbookEntry::factory()->count(10)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_AGENCY,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('Maximum bonus reached!')
        ->assertSee('10 / 10');
});

test('only counts entries for specific event configuration', function () {
    $otherEventConfig = EventConfiguration::factory()->create();

    // Entries for our event
    GuestbookEntry::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    // Entries for other event
    GuestbookEntry::factory()->count(5)->create([
        'event_configuration_id' => $otherEventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('3 / 10')
        ->assertSee('300');
});

test('calculates progress percentage correctly', function () {
    GuestbookEntry::factory()->count(5)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    $component = Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id]);

    expect($component->progressPercentage)->toBe(50);
});

test('caps progress percentage at 100', function () {
    GuestbookEntry::factory()->count(15)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    $component = Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id]);

    expect($component->progressPercentage)->toBe(100);
});
