<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\InterestAvailability;
use App\Enums\LicenseClass;
use App\Livewire\Interest\InterestForm;
use App\Livewire\Schedule\InterestResponses;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventInterest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config(['auth-security.registration_mode' => 'open']);
    RateLimiter::clear('event-interest:127.0.0.1');

    $this->event = Event::factory()->create([
        'name' => 'Field Day 2027',
        'start_time' => now()->addMonths(3),
        'end_time' => now()->addMonths(3)->addDay(),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'interest_form_enabled' => true,
    ]);
});

/**
 * Fill in a valid response on the form.
 */
function fillInterestForm(mixed $component, array $overrides = []): mixed
{
    $values = array_merge([
        'name' => 'Pat Smith',
        'callsign' => 'k1abc',
        'email' => 'Pat@Example.com',
        'license_class' => LicenseClass::General->value,
        'availability' => [InterestAvailability::Setup->value, InterestAvailability::Overnight->value],
        'notes' => 'I can bring a generator.',
    ], $overrides);

    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

describe('public form', function () {
    test('the page loads for visitors without an account', function () {
        $this->get(route('interest.form'))
            ->assertOk()
            ->assertSee('Field Day 2027')
            ->assertSee('autocapitalize="characters"', false)
            ->assertSee('autocomplete="email"', false);
    });

    test('saves a response for the next event', function () {
        fillInterestForm(Livewire::test(InterestForm::class))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $interest = EventInterest::sole();

        expect($interest->event_configuration_id)->toBe($this->eventConfig->id)
            ->and($interest->email)->toBe('pat@example.com')
            ->and($interest->callsign)->toBe('K1ABC')
            ->and($interest->license_class)->toBe(LicenseClass::General)
            ->and($interest->availability->all())->toBe([InterestAvailability::Setup, InterestAvailability::Overnight])
            ->and($interest->user_id)->toBeNull()
            ->and(AuditLog::where('action', 'interest.response.sent')->where('auditable_id', $interest->id)->exists())->toBeTrue();
    });

    test('requires a name, an email and at least one time', function () {
        Livewire::test(InterestForm::class)
            ->call('save')
            ->assertHasErrors(['name', 'email', 'availability']);

        expect(EventInterest::count())->toBe(0);
    });

    test('call sign and license class are optional', function () {
        fillInterestForm(Livewire::test(InterestForm::class), ['callsign' => '', 'license_class' => ''])
            ->call('save')
            ->assertHasNoErrors();

        expect(EventInterest::sole())
            ->callsign->toBeNull()
            ->license_class->toBeNull();
    });

    test('sending again with the same email updates the earlier response', function () {
        fillInterestForm(Livewire::test(InterestForm::class))->call('save');

        fillInterestForm(Livewire::test(InterestForm::class), [
            'email' => 'pat@example.com',
            'availability' => [InterestAvailability::Teardown->value],
        ])
            ->call('save')
            ->assertSet('updatedExisting', true);

        expect(EventInterest::sole()->availability->all())->toBe([InterestAvailability::Teardown]);
    });

    test('a visitor cannot overwrite a response tied to an account', function () {
        $owner = User::factory()->create(['email' => 'pat@example.com']);
        EventInterest::factory()->for($this->eventConfig)->create([
            'email' => 'pat@example.com',
            'user_id' => $owner->id,
            'notes' => 'original',
        ]);

        fillInterestForm(Livewire::test(InterestForm::class), ['notes' => 'replaced'])
            ->call('save')
            ->assertHasErrors('form');

        expect(EventInterest::sole()->notes)->toBe('original');
    });

    test('is closed when the form is turned off', function () {
        $this->eventConfig->update(['interest_form_enabled' => false]);

        Livewire::test(InterestForm::class)
            ->assertSet('eventId', null)
            ->assertDontSee('wire:submit="save"', false);
    });

    test('never takes responses for an event that has ended', function () {
        $this->event->update(['start_time' => now()->subDays(3), 'end_time' => now()->subDays(2)]);

        Livewire::test(InterestForm::class)->assertSet('eventId', null);
    });

    test('quietly drops a filled-in honeypot', function () {
        fillInterestForm(Livewire::test(InterestForm::class), ['honeypot' => 'spam'])
            ->call('save')
            ->assertSet('submitted', true);

        expect(EventInterest::count())->toBe(0);
    });

    test('limits how often one network can send responses', function () {
        foreach (range(1, InterestForm::RATE_LIMIT) as $i) {
            fillInterestForm(Livewire::test(InterestForm::class), ['email' => "person{$i}@example.com"])->call('save');
        }

        fillInterestForm(Livewire::test(InterestForm::class), ['email' => 'one-more@example.com'])
            ->call('save')
            ->assertHasErrors('form');

        expect(EventInterest::count())->toBe(InterestForm::RATE_LIMIT);
    });
});

describe('accounts', function () {
    test('fills in a signed-in user\'s details and links the response', function () {
        $user = User::factory()->create([
            'first_name' => 'Pat',
            'last_name' => 'Smith',
            'call_sign' => 'K1ABC',
            'email' => 'pat@example.com',
            'license_class' => 'Extra',
        ]);

        $this->actingAs($user);

        Livewire::test(InterestForm::class)
            ->assertSet('name', 'Pat Smith')
            ->assertSet('callsign', 'K1ABC')
            ->assertSet('email', 'pat@example.com')
            ->assertSet('license_class', 'Extra')
            ->set('availability', [InterestAvailability::Daytime->value])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('submitted', true)
            ->assertDontSee(route('register'));

        expect(EventInterest::sole()->user_id)->toBe($user->id);
    });

    test('a signed-in user sees their earlier answers', function () {
        $user = User::factory()->create(['email' => 'pat@example.com']);
        EventInterest::factory()->for($this->eventConfig)->create([
            'email' => 'pat@example.com',
            'user_id' => $user->id,
            'availability' => [InterestAvailability::Evening],
            'notes' => 'Only Saturday',
        ]);

        $this->actingAs($user);

        Livewire::test(InterestForm::class)
            ->assertSet('availability', ['evening'])
            ->assertSet('notes', 'Only Saturday');
    });

    test('invites a visitor to create an account when registration is open', function () {
        fillInterestForm(Livewire::test(InterestForm::class))
            ->call('save')
            ->assertSee(route('register'));
    });

    test('does not invite a visitor to register when registration is disabled', function () {
        config(['auth-security.registration_mode' => 'disabled']);

        fillInterestForm(Livewire::test(InterestForm::class))
            ->call('save')
            ->assertDontSee(route('register'));
    });

    test('points a visitor who already has an account to sign in', function () {
        User::factory()->create(['email' => 'pat@example.com']);

        fillInterestForm(Livewire::test(InterestForm::class))
            ->call('save')
            ->assertDontSee(route('register'))
            ->assertSee(route('login'));
    });

    test('the registration page starts with the visitor\'s details', function () {
        session()->put(InterestForm::PREFILL_SESSION_KEY, [
            'first_name' => 'Pat',
            'last_name' => 'Smith',
            'call_sign' => 'K1ABC',
            'email' => 'pat@example.com',
            'license_class' => 'General',
        ]);

        $this->get('/register')
            ->assertOk()
            ->assertSee('value="K1ABC"', false)
            ->assertSee('value="Pat"', false)
            ->assertSee('value="Smith"', false)
            ->assertSee('value="pat@example.com"', false)
            ->assertSee('name="license_class" value="General"', false);
    });

    test('registering claims the visitor\'s responses and keeps the license class', function () {
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
        EventInterest::factory()->for($this->eventConfig)->create(['email' => 'pat@example.com']);

        $this->withSession([InterestForm::PREFILL_SESSION_KEY => ['email' => 'pat@example.com']])
            ->post('/register', [
                'call_sign' => 'K1ABC',
                'first_name' => 'Pat',
                'last_name' => 'Smith',
                'email' => 'Pat@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'license_class' => 'General',
            ])
            ->assertSessionMissing(InterestForm::PREFILL_SESSION_KEY);

        $user = User::where('call_sign', 'K1ABC')->sole();

        expect($user->license_class)->toBe('General')
            ->and(EventInterest::sole()->user_id)->toBe($user->id);
    });
});

describe('organizer view', function () {
    beforeEach(function () {
        Permission::firstOrCreate(['name' => 'manage-shifts']);
        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo('manage-shifts');
    });

    test('needs the manage-shifts permission', function () {
        $this->actingAs(User::factory()->create());

        Livewire::test(InterestResponses::class, ['eventConfigId' => $this->eventConfig->id])
            ->assertForbidden();
    });

    test('lists responses with counts for each time', function () {
        EventInterest::factory()->for($this->eventConfig)->create([
            'name' => 'Pat Smith',
            'availability' => [InterestAvailability::Setup, InterestAvailability::Overnight],
        ]);
        EventInterest::factory()->for($this->eventConfig)->create([
            'name' => 'Sam Jones',
            'availability' => [InterestAvailability::Setup],
        ]);

        $this->actingAs($this->manager);

        $component = Livewire::test(InterestResponses::class, ['eventConfigId' => $this->eventConfig->id])
            ->assertSee('Pat Smith')
            ->assertSee('Sam Jones')
            ->assertSee(route('interest.form'));

        expect(collect($component->instance()->availabilityTally)->pluck('count', 'label')->all())->toMatchArray([
            'Setup' => 2,
            'Overnight' => 1,
            'Daytime' => 0,
        ]);
    });

    test('turns the public form on and off', function () {
        $this->actingAs($this->manager);

        Livewire::test(InterestResponses::class, ['eventConfigId' => $this->eventConfig->id])
            ->set('formEnabled', false);

        expect($this->eventConfig->fresh()->interest_form_enabled)->toBeFalse()
            ->and(AuditLog::where('action', 'interest.form.closed')->exists())->toBeTrue();
    });

    test('cannot open the form for an event that has ended', function () {
        $this->eventConfig->update(['interest_form_enabled' => false]);
        $this->event->update(['start_time' => now()->subDays(3), 'end_time' => now()->subDays(2)]);

        $this->actingAs($this->manager);

        Livewire::test(InterestResponses::class, ['eventConfigId' => $this->eventConfig->id])
            ->set('formEnabled', true)
            ->assertSet('formEnabled', false);

        expect($this->eventConfig->fresh()->interest_form_enabled)->toBeFalse();
    });

    test('deletes a response', function () {
        $interest = EventInterest::factory()->for($this->eventConfig)->create();

        $this->actingAs($this->manager);

        Livewire::test(InterestResponses::class, ['eventConfigId' => $this->eventConfig->id])
            ->call('deleteResponse', $interest->id);

        expect(EventInterest::count())->toBe(0)
            ->and(AuditLog::where('action', 'interest.response.deleted')->exists())->toBeTrue();
    });

    test('cannot delete a response from another event', function () {
        $other = EventInterest::factory()->create();

        $this->actingAs($this->manager);

        Livewire::test(InterestResponses::class, ['eventConfigId' => $this->eventConfig->id])
            ->call('deleteResponse', $other->id)
            ->assertNotFound();

        expect(EventInterest::count())->toBe(1);
    });
});
