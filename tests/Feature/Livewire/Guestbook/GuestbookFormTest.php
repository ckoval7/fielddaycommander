<?php

use App\Livewire\Guestbook\GuestbookForm;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    // Create active event with guestbook enabled (within current date range)
    $this->event = Event::factory()->create([
        'is_active' => true,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'guestbook_enabled' => true,
    ]);

    // Clear rate limiter before each test
    RateLimiter::clear('guestbook-entry:'.request()->ip());
});

describe('rendering', function () {
    test('renders the guestbook form', function () {
        Livewire::test(GuestbookForm::class)
            ->assertStatus(200)
            ->assertSet('eventConfig.id', $this->eventConfig->id);
    });

    test('shows message when no active event', function () {
        // Remove event entirely so no context event is found
        $this->event->forceDelete();

        $component = Livewire::test(GuestbookForm::class);

        expect($component->get('eventConfig'))->toBeNull();
    });

    test('loads event config during pre-event setup window', function () {
        // Replace the active event with one that hasn't started yet but is in the setup window
        $this->event->forceDelete();

        $setupEvent = Event::factory()->create([
            'setup_allowed_from' => now()->subHours(2),
            'start_time' => now()->addHours(12),
            'end_time' => now()->addHours(39),
        ]);
        $setupConfig = EventConfiguration::factory()->create([
            'event_id' => $setupEvent->id,
            'guestbook_enabled' => true,
        ]);

        $component = Livewire::test(GuestbookForm::class);

        expect($component->get('eventConfig.id'))->toBe($setupConfig->id);
    });
});

describe('pre-fill', function () {
    test('pre-fills form for authenticated users', function () {
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'call_sign' => 'KC1ABC',
            'email' => 'jane@example.com',
        ]);

        $this->actingAs($user);

        Livewire::test(GuestbookForm::class)
            ->assertSet('name', 'Jane Smith')
            ->assertSet('callsign', 'KC1ABC')
            ->assertSet('email', 'jane@example.com');
    });

    test('does not pre-fill for guests', function () {
        Livewire::test(GuestbookForm::class)
            ->assertSet('name', '')
            ->assertSet('callsign', '')
            ->assertSet('email', '');
    });
});

describe('validation', function () {
    test('requires name field', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', '')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasErrors('name');
    });

    test('validates email format', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('email', 'invalid-email')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasErrors('email');
    });

    test('validates presence type', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('presence_type', 'invalid_type')
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasErrors('presence_type');
    });

    test('validates visitor category', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', 'invalid_category')
            ->call('save')
            ->assertHasErrors('visitor_category');
    });

    test('limits comments to 500 characters', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('comments', str_repeat('a', 501))
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasErrors('comments');
    });

    test('validates required fields', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', '')
            ->set('presence_type', '')
            ->set('visitor_category', '')
            ->call('save')
            ->assertHasErrors(['name', 'presence_type', 'visitor_category']);
    });
});

describe('submission', function () {
    test('creates guestbook entry on submit', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('callsign', 'W1AW')
            ->set('email', 'john@example.com')
            ->set('comments', 'Great event!')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasNoErrors();

        expect(GuestbookEntry::count())->toBe(1);
        $entry = GuestbookEntry::first();
        expect($entry->first_name)->toBe('John');
        expect($entry->last_name)->toBe('Doe');
        expect($entry->callsign)->toBe('W1AW');
        expect($entry->email)->toBe('john@example.com');
        expect($entry->comments)->toBe('Great event!');
    });

    test('captures ip address', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasNoErrors();

        $entry = GuestbookEntry::first();
        expect($entry->ip_address)->toBeTruthy();
    });

    test('associates user when authenticated', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasNoErrors();

        $entry = GuestbookEntry::first();
        expect($entry->user_id)->toBe($user->id);
    });

    test('shows success message after submission', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertSet('showSuccess', true);
    });

    test('resets form fields after save for guests', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('callsign', 'W1AW')
            ->set('email', 'john@example.com')
            ->set('comments', 'Great event!')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertSet('comments', '')
            ->assertSet('honeypot', '')
            ->assertSet('visitor_category', 'general_public')
            ->assertSet('name', '')
            ->assertSet('callsign', '')
            ->assertSet('email', '');
    });

    test('resets form fields but keeps user data for authenticated users', function () {
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'call_sign' => 'KC1ABC',
            'email' => 'jane@example.com',
        ]);

        $this->actingAs($user);

        Livewire::test(GuestbookForm::class)
            ->set('comments', 'Great event!')
            ->call('save')
            ->assertSet('comments', '')
            ->assertSet('honeypot', '')
            ->assertSet('visitor_category', 'general_public')
            // User data should be kept
            ->assertSet('name', 'Jane Smith')
            ->assertSet('callsign', 'KC1ABC')
            ->assertSet('email', 'jane@example.com');
    });

    test('converts callsign to uppercase', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('callsign', 'w1aw')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasNoErrors();

        expect(GuestbookEntry::first()->callsign)->toBe('W1AW');
    });
});

describe('security', function () {
    test('rejects submission when honeypot is filled', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('honeypot', 'spam-value')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save');

        // Should silently fail (no entry created)
        expect(GuestbookEntry::count())->toBe(0);
    });

    test('rate limits submissions', function () {
        // Submit 5 entries from the same IP
        for ($i = 1; $i <= 5; $i++) {
            Livewire::test(GuestbookForm::class)
                ->set('name', "Visitor {$i}")
                ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
                ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
                ->call('save')
                ->assertHasNoErrors();
        }

        expect(GuestbookEntry::count())->toBe(5);

        // 6th submission should be rate limited
        Livewire::test(GuestbookForm::class)
            ->set('name', 'Visitor 6')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasErrors('form');

        expect(GuestbookEntry::count())->toBe(5);
    });

    test('shows friendly rate limit error message', function () {
        // Submit 5 entries to hit the rate limit
        for ($i = 1; $i <= 5; $i++) {
            Livewire::test(GuestbookForm::class)
                ->set('name', "Visitor {$i}")
                ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
                ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
                ->call('save');
        }

        // Next submission should show friendly error message with form error
        Livewire::test(GuestbookForm::class)
            ->set('name', 'Visitor 6')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasErrors('form');
    });

    test('rate limiter resets after clearing', function () {
        $rateLimitKey = 'guestbook-entry:'.request()->ip();

        // Hit the rate limit
        for ($i = 1; $i <= 5; $i++) {
            Livewire::test(GuestbookForm::class)
                ->set('name', "Visitor {$i}")
                ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
                ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
                ->call('save');
        }

        expect(GuestbookEntry::count())->toBe(5);

        // Now clear the rate limiter (simulating 1 hour passing)
        RateLimiter::clear($rateLimitKey);

        // Should be able to submit again
        Livewire::test(GuestbookForm::class)
            ->set('name', 'Visitor 6')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasNoErrors();

        expect(GuestbookEntry::count())->toBe(6);
    });

    test('rejects submission when guestbook is disabled', function () {
        $this->eventConfig->update(['guestbook_enabled' => false]);

        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasErrors('form');

        expect(GuestbookEntry::count())->toBe(0);
    });
});

describe('events', function () {
    test('dispatches guestbook-entry-created event on submission', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Doe')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertDispatched('guestbook-entry-created');
    });
});

describe('audit logging', function () {
    test('logs audit entry when guestbook is signed by an authenticated user', function () {
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);
        $this->actingAs($user);

        Livewire::test(GuestbookForm::class)
            ->set('name', 'Jane Smith')
            ->set('callsign', 'KC1ABC')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_IN_PERSON)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_MEDIA)
            ->call('save')
            ->assertHasNoErrors();

        $entry = GuestbookEntry::latest()->first();
        $auditLog = AuditLog::where('action', 'guestbook.entry.signed')->first();

        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($user->id);
        expect($auditLog->auditable_type)->toBe(GuestbookEntry::class);
        expect($auditLog->auditable_id)->toBe($entry->id);
        expect($auditLog->new_values)->toMatchArray([
            'name' => 'Jane Smith',
            'callsign' => 'KC1ABC',
            'presence_type' => GuestbookEntry::PRESENCE_TYPE_IN_PERSON,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        ]);
    });

    test('logs audit entry when guestbook is signed by an anonymous visitor', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'John Public')
            ->set('callsign', '')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save')
            ->assertHasNoErrors();

        $entry = GuestbookEntry::latest()->first();
        $auditLog = AuditLog::where('action', 'guestbook.entry.signed')->first();

        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBeNull();
        expect($auditLog->auditable_type)->toBe(GuestbookEntry::class);
        expect($auditLog->auditable_id)->toBe($entry->id);
        expect($auditLog->new_values)->toMatchArray([
            'name' => 'John Public',
            'callsign' => null,
            'presence_type' => GuestbookEntry::PRESENCE_TYPE_ONLINE,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
        ]);
    });

    test('does not log audit entry when honeypot is triggered', function () {
        Livewire::test(GuestbookForm::class)
            ->set('name', 'Bot Name')
            ->set('honeypot', 'spam-value')
            ->set('presence_type', GuestbookEntry::PRESENCE_TYPE_ONLINE)
            ->set('visitor_category', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->call('save');

        expect(AuditLog::where('action', 'guestbook.entry.signed')->count())->toBe(0);
    });
});
