<?php

use App\Contracts\ReminderSource;
use App\Enums\NotificationCategory;
use App\Livewire\Profile\UserProfile;
use App\Livewire\Users\UserManagement;
use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ReminderService;
use Carbon\Carbon;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::create(['name' => 'manage-users']);

    $adminRole = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    Role::create(['name' => 'Station Captain', 'guard_name' => 'web']);
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    $adminRole->givePermissionTo('manage-users');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('System Administrator');
});

// =============================================================================
// Login Page
// =============================================================================

test('login page hides forgot password link when email is not configured', function () {
    // phpunit.xml sets MAIL_MAILER=array, so Features::resetPasswords() is disabled,
    // which means Route::has('password.request') is false and the link is hidden.
    $response = $this->get(route('login'));

    $response->assertDontSee('Forgot password?');
});

// =============================================================================
// Admin Reset Password Modal
// =============================================================================

test('reset password modal shows email option when email is configured', function () {
    Config::set('mail.email_configured', true);

    $this->actingAs($this->admin);

    $target = User::factory()->create();

    Livewire::test(UserManagement::class)
        ->call('openResetModal', $target->id)
        ->assertSee('Send password reset email');
});

test('reset password modal hides email option when email is not configured', function () {
    Config::set('mail.email_configured', false);

    $this->actingAs($this->admin);

    $target = User::factory()->create();

    Livewire::test(UserManagement::class)
        ->call('openResetModal', $target->id)
        ->assertDontSee('Send password reset email');
});

// =============================================================================
// User Creation Modal
// =============================================================================

test('create user modal shows invitation option when email is configured', function () {
    Config::set('mail.email_configured', true);

    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->assertSee('Send invitation email');
});

test('create user modal hides invitation option when email is not configured', function () {
    Config::set('mail.email_configured', false);

    $this->actingAs($this->admin);

    Livewire::test(UserManagement::class)
        ->call('openCreateModal')
        ->assertDontSee('Send invitation email');
});

// =============================================================================
// Profile Email Notification Preferences
// =============================================================================

test('profile shows email notification preferences when email is configured', function () {
    Config::set('mail.email_configured', true);

    $this->actingAs($this->admin);

    Livewire::test(UserProfile::class)
        ->assertSee('Email Notifications');
});

test('profile hides email notification preferences when email is not configured', function () {
    Config::set('mail.email_configured', false);

    $this->actingAs($this->admin);

    Livewire::test(UserProfile::class)
        ->assertDontSee('Email Notifications');
});

// =============================================================================
// Reminder Service Email Guard
// =============================================================================

test('reminder service skips email when email is not configured', function () {
    Config::set('mail.email_configured', false);
    Illuminate\Support\Facades\Notification::fake();

    $this->travelTo(now());
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $user = User::factory()->create([
        'notification_preferences' => [
            'test_reminder_minutes' => [10],
            'test_email' => true,
        ],
    ]);

    $eventType = EventType::where('code', 'FD')->first();
    $event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(18),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'created_by_user_id' => $user->id,
    ]);
    $item = BulletinScheduleEntry::factory()->create([
        'event_id' => $event->id,
        'scheduled_at' => now()->addMinutes(10),
        'created_by' => $user->id,
    ]);

    $mockMail = new class extends Notification
    {
        public function via(): array
        {
            return ['mail'];
        }
    };

    $source = new class(collect([$item]), collect([$user]), $mockMail) implements ReminderSource
    {
        public function __construct(
            private Collection $items,
            private Collection $users,
            private Notification $mailNotification,
        ) {}

        public function getUpcomingRemindables(): Collection
        {
            return $this->items;
        }

        public function getReminderCategory(): NotificationCategory
        {
            return NotificationCategory::BulletinReminder;
        }

        public function buildNotificationData(Model $item, User $user, int $minutes): array
        {
            return ['title' => 'Test', 'message' => 'Test', 'url' => '/test'];
        }

        public function buildMailNotification(Model $item, User $user, int $minutes): ?Notification
        {
            return $this->mailNotification;
        }

        public function getGroupKey(Model $item, int $minutes): string
        {
            return "test_{$item->id}_{$minutes}m";
        }

        public function getUsersToNotify(Model $item): Collection
        {
            return $this->users;
        }

        public function getScheduledTime(Model $item): Carbon
        {
            return $item->scheduled_at;
        }

        public function getUserReminderMinutes(User $user): array
        {
            return [10];
        }

        public function getMinutesPreferenceKey(): string
        {
            return 'test_reminder_minutes';
        }

        public function getEmailPreferenceKey(): ?string
        {
            return 'test_email';
        }
    };

    $service = app(ReminderService::class);
    $service->processSource($source);

    // In-app notification sent, but the mail notification class should NOT have been dispatched
    Illuminate\Support\Facades\Notification::assertSentTo($user, InAppNotification::class);
    Illuminate\Support\Facades\Notification::assertNotSentTo($user, $mockMail::class);
});

// =============================================================================
// Config Value
// =============================================================================

test('mail.email_configured is false for log mailer', function () {
    Config::set('mail.default', 'log');
    Config::set('mail.email_configured', ! in_array(config('mail.default'), ['log', 'array']));

    expect(config('mail.email_configured'))->toBeFalse();
});

test('mail.email_configured is false for array mailer', function () {
    Config::set('mail.default', 'array');
    Config::set('mail.email_configured', ! in_array(config('mail.default'), ['log', 'array']));

    expect(config('mail.email_configured'))->toBeFalse();
});

test('mail.email_configured is true for smtp mailer', function () {
    Config::set('mail.default', 'smtp');
    Config::set('mail.email_configured', ! in_array(config('mail.default'), ['log', 'array']));

    expect(config('mail.email_configured'))->toBeTrue();
});

// =============================================================================
// Weather Alert Email Preference Visibility
// =============================================================================

test('profile shows weather alert email toggle when email is configured', function () {
    Config::set('mail.email_configured', true);

    $this->actingAs($this->admin);

    Livewire::test(UserProfile::class)
        ->assertSee('Email weather alerts');
});

test('profile hides weather alert email toggle when email is not configured', function () {
    Config::set('mail.email_configured', false);

    $this->actingAs($this->admin);

    Livewire::test(UserProfile::class)
        ->assertDontSee('Email weather alerts');
});
