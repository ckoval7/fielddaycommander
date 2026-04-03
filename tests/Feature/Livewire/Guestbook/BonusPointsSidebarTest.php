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

test('renders with bonus items showing zero counts when no entries exist', function () {
    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertOk()
        ->assertSee('Guestbook Bonuses')
        ->assertSee('Elected Official Visit')
        ->assertSee('Served Agency Visit')
        ->assertSee('Media Publicity')
        ->assertSee('of 300 possible');
});

test('shows earned status when verified elected official entry exists', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('Elected Official Visit')
        ->assertSee('+100');
});

test('shows earned status when verified agency entry exists', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_AGENCY,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('Served Agency Visit')
        ->assertSee('+100');
});

test('shows earned status when verified media entry exists', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('Media Publicity')
        ->assertSee('+100');
});

test('arrl officials show tracked but not bonus-eligible text when present', function () {
    GuestbookEntry::factory()->count(2)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ARRL_OFFICIAL,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('ARRL Officials')
        ->assertSee('Tracked but not bonus-eligible per rules');
});

test('arrl officials section is hidden when none exist', function () {
    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertDontSee('ARRL Officials')
        ->assertDontSee('Tracked but not bonus-eligible per rules');
});

test('total bonus points equals 100 per earned bonus type with max of 300', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    $component = Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id]);

    expect($component->get('totalBonusPoints'))->toBe(200);
    expect($component->get('maxBonusPoints'))->toBe(300);
});

test('shows all guestbook bonuses earned when all three are earned', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_AGENCY,
        'is_verified' => true,
    ]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('All guestbook bonuses earned!')
        ->assertDontSee('Still needed:');
});

test('shows still needed alert listing missing bonuses when some are unearned', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id])
        ->assertSee('Still needed:')
        ->assertSee('Served Agency Visit')
        ->assertSee('Media Publicity')
        ->assertDontSee('All guestbook bonuses earned!');
});

test('only counts verified entries for bonus status', function () {
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    GuestbookEntry::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_AGENCY,
        'is_verified' => false,
    ]);

    $component = Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id]);

    expect($component->get('totalBonusPoints'))->toBe(100);
});

test('only counts entries for specific event configuration', function () {
    $otherEventConfig = EventConfiguration::factory()->create();

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);

    GuestbookEntry::factory()->create([
        'event_configuration_id' => $otherEventConfig->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_AGENCY,
        'is_verified' => true,
    ]);

    $component = Livewire::test(BonusPointsSidebar::class, ['eventConfigId' => $this->eventConfig->id]);

    expect($component->get('totalBonusPoints'))->toBe(100);
});
