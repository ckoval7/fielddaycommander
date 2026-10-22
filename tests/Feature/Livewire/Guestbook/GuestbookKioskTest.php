<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\PresenceType;
use App\Enums\VisitorCategory;
use App\Livewire\Guestbook\GuestbookForm;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Setting::set('setup_completed', 'true');

    $this->event = Event::factory()->create([
        'is_active' => true,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'guestbook_enabled' => true,
    ]);

    RateLimiter::clear('guestbook-entry:'.request()->ip());
    RateLimiter::clear('guestbook-kiosk-entry:'.request()->ip());
});

describe('kiosk page', function () {
    test('opens without signing in', function () {
        $this->get(route('guestbook.kiosk'))
            ->assertOk()
            ->assertSee('Sign the Guestbook')
            ->assertSee('Recent Visitors');
    });

    test('leaves out the app header and sidebar', function () {
        $this->get(route('guestbook.kiosk'))
            ->assertOk()
            ->assertDontSee('main-drawer', false)
            ->assertDontSee('<header', false);
    });

    test('puts the form above the recent entries', function () {
        $html = $this->get(route('guestbook.kiosk'))->getContent();

        expect(strpos($html, 'Sign the Guestbook'))->toBeLessThan(strpos($html, 'Recent Visitors'));
    });

    test('reloads itself when the session expires instead of leaving the page', function () {
        $this->get(route('guestbook.kiosk'))
            ->assertSee('data-reload-on-session-expiry', false);
    });

    test('links back to the regular guestbook', function () {
        $this->get(route('guestbook.kiosk'))
            ->assertSee('Exit kiosk')
            ->assertSee(route('guestbook.index'), false);
    });

    test('lists recent entries newest first', function () {
        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Older',
            'created_at' => now()->subHour(),
        ]);
        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Newer',
            'created_at' => now(),
        ]);

        $this->get(route('guestbook.kiosk'))
            ->assertSeeInOrder(['Newer', 'Older']);
    });

    test('says the guestbook is closed when no event accepts entries', function () {
        $this->eventConfig->update(['guestbook_enabled' => false]);

        $this->get(route('guestbook.kiosk'))
            ->assertOk()
            ->assertSee('No Active Event')
            ->assertDontSee('Sign the Guestbook');
    });

    test('opens during the setup window', function (string $route) {
        $this->event->update([
            'start_time' => now()->addHours(6),
            'end_time' => now()->addHours(30),
            'setup_allowed_from' => now()->subHour(),
        ]);

        $this->get(route($route))
            ->assertOk()
            ->assertSee('Sign the Guestbook');
    })->with(['guestbook.kiosk', 'guestbook.index']);

    test('stays closed outside an event and its setup window', function (string $route, array $times) {
        $this->event->update($times);

        $this->get(route($route))
            ->assertOk()
            ->assertSee('No Active Event')
            ->assertDontSee('Sign the Guestbook');
    })->with(['guestbook.kiosk', 'guestbook.index'])->with([
        'before setup opens' => fn () => [
            'start_time' => now()->addDays(10),
            'end_time' => now()->addDays(11),
            'setup_allowed_from' => now()->addDays(9),
        ],
        'after the event ends' => fn () => [
            'start_time' => now()->subDays(3),
            'end_time' => now()->subDays(2),
            'setup_allowed_from' => now()->subDays(4),
        ],
    ]);

    test('signs the running event while a past event is being browsed', function () {
        $pastEvent = Event::factory()->create([
            'start_time' => now()->subYear(),
            'end_time' => now()->subYear()->addDay(),
            'setup_allowed_from' => now()->subYear()->subDay(),
        ]);
        EventConfiguration::factory()->create([
            'event_id' => $pastEvent->id,
            'guestbook_enabled' => true,
        ]);
        session(['viewing_event_id' => $pastEvent->id]);

        Livewire::test(GuestbookForm::class, ['kiosk' => true])
            ->assertSet('eventConfig.id', $this->eventConfig->id);
    });

    test('sizes the entry grid to its column rather than the screen', function () {
        GuestbookEntry::factory()->create(['event_configuration_id' => $this->eventConfig->id]);

        $this->get(route('guestbook.index'))
            ->assertSee('@container', false)
            ->assertSee('grid-cols-1 @xl:grid-cols-2 @4xl:grid-cols-3', false);
    });

    test('gives each part of the guestbook page one heading', function () {
        $this->get(route('guestbook.index'))
            ->assertDontSee('Add Your Entry')
            ->assertDontSee('Recent Entries')
            ->assertSee('Sign the Guestbook')
            ->assertSee('Recent Visitors');
    });

    test('is offered from the guestbook page', function () {
        $this->get(route('guestbook.index'))
            ->assertSee('Kiosk mode')
            ->assertSee(route('guestbook.kiosk'), false);
    });
});

describe('kiosk form', function () {
    test('does not pre-fill a signed-in user', function () {
        $this->actingAs(User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'call_sign' => 'KC1ABC',
            'email' => 'jane@example.com',
        ]));

        Livewire::test(GuestbookForm::class, ['kiosk' => true])
            ->assertSet('name', '')
            ->assertSet('callsign', '')
            ->assertSet('email', '');
    });

    test('starts visitors as in person and skips location detection', function () {
        Livewire::test(GuestbookForm::class, ['kiosk' => true])
            ->assertSet('presence_type', PresenceType::InPerson->value)
            ->assertSeeHtml('kiosk: true')
            ->assertDontSee('Re-detect location');
    });

    test('does not tie entries to whoever is signed in on the kiosk', function () {
        $this->actingAs(User::factory()->create());

        Livewire::test(GuestbookForm::class, ['kiosk' => true])
            ->set('name', 'Walk Up')
            ->set('visitor_category', VisitorCategory::GeneralPublic->value)
            ->call('save')
            ->assertHasNoErrors();

        expect(GuestbookEntry::sole()->user_id)->toBeNull();
    });

    test('clears every field for the next visitor, even when signed in', function () {
        $this->actingAs(User::factory()->create());

        Livewire::test(GuestbookForm::class, ['kiosk' => true])
            ->set('name', 'Walk Up')
            ->set('callsign', 'W1AW')
            ->set('email', 'walkup@example.com')
            ->set('presence_type', PresenceType::Online->value)
            ->call('save')
            ->assertSet('name', '')
            ->assertSet('callsign', '')
            ->assertSet('email', '')
            ->assertSet('presence_type', PresenceType::InPerson->value);
    });

    test('counts down on the thank-you screen, then returns to a blank form', function () {
        Livewire::test(GuestbookForm::class, ['kiosk' => true])
            ->set('name', 'Walk Up')
            ->call('save')
            ->assertSet('showSuccess', true)
            ->assertSeeHtml('data-kiosk-countdown')
            ->assertSeeHtml('class="radial-progress')
            ->assertDontSeeHtml('x-text="Math.ceil')
            ->assertSee('This screen clears for the next visitor in '.GuestbookForm::KIOSK_THANK_YOU_SECONDS.' seconds.')
            ->assertSeeHtml('$wire.hideSuccess()')
            ->assertSeeHtml('total: '.GuestbookForm::KIOSK_THANK_YOU_SECONDS * 1000);
    });

    test('keeps the thank-you screen on personal devices', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'Visitor')
            ->set('presence_type', PresenceType::Online->value)
            ->call('save')
            ->assertSet('showSuccess', true)
            ->assertDontSeeHtml('data-kiosk-countdown');
    });

    test('takes more than the per-device limit, since every visitor shares one browser', function () {
        for ($i = 1; $i <= GuestbookForm::RATE_LIMIT + 1; $i++) {
            Livewire::test(GuestbookForm::class, ['kiosk' => true])
                ->set('name', "Visitor {$i}")
                ->call('save')
                ->assertHasNoErrors();
        }

        expect(GuestbookEntry::count())->toBe(GuestbookForm::RATE_LIMIT + 1);
    });

    test('still rate limits the kiosk', function () {
        RateLimiter::increment('guestbook-kiosk-entry:'.request()->ip(), 3600, GuestbookForm::KIOSK_RATE_LIMIT);

        Livewire::test(GuestbookForm::class, ['kiosk' => true])
            ->set('name', 'One Too Many')
            ->call('save')
            ->assertHasErrors('form');

        expect(GuestbookEntry::count())->toBe(0);
    });

    test('cannot be switched into kiosk mode from the browser', function () {
        Livewire::test(GuestbookForm::class)
            ->set('kiosk', true);
    })->throws(CannotUpdateLockedPropertyException::class);
});
