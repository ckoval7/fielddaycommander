<?php

use App\Livewire\Components\NotificationBell;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

test('component renders for authenticated users', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertOk()
        ->assertSee('Notifications');
});

test('component shows unread count when notifications exist', function () {
    $user = User::factory()->create();

    createNotification($user, ['title' => 'Test 1']);
    createNotification($user, ['title' => 'Test 2']);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('2');
});

test('component hides badge when no unread notifications', function () {
    $user = User::factory()->create();

    $notification = createNotification($user, ['title' => 'Read notification']);
    $notification->markAsRead();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSet('unreadCount', 0);
});

test('component shows notification content', function () {
    $user = User::factory()->create();

    createNotification($user, [
        'title' => 'New Section Worked!',
        'message' => 'W1AW worked CT on 20m CW',
        'icon' => 'o-globe-americas',
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('New Section Worked!')
        ->assertSee('W1AW worked CT on 20m CW');
});

test('mark as read marks a single notification', function () {
    $user = User::factory()->create();

    $notification = createNotification($user, ['title' => 'Test']);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSet('unreadCount', 1)
        ->call('markAsRead', $notification->id)
        ->assertSet('unreadCount', 0);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('mark all as read marks all unread notifications', function () {
    $user = User::factory()->create();

    createNotification($user, ['title' => 'Test 1']);
    createNotification($user, ['title' => 'Test 2']);
    createNotification($user, ['title' => 'Test 3']);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSet('unreadCount', 3)
        ->call('markAllAsRead')
        ->assertSet('unreadCount', 0);

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('open notification marks as read and redirects', function () {
    $user = User::factory()->create();

    $notification = createNotification($user, [
        'title' => 'Test',
        'url' => '/contacts',
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->call('openNotification', $notification->id)
        ->assertRedirect('/contacts');

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('component limits to 20 notifications', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 25; $i++) {
        createNotification($user, ['title' => "Notification {$i}"]);
    }

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSet('unreadCount', 25)
        ->assertCount('notifications', 20);
});

test('shows empty state when no notifications', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('No notifications yet');
});

test('shows notification settings link', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSee('Notification Settings');
});

test('load notifications responds to event', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(NotificationBell::class)
        ->assertSet('unreadCount', 0);

    createNotification($user, ['title' => 'New one']);

    $component->call('loadNotifications')
        ->assertSet('unreadCount', 1);
});

/**
 * Helper to create a database notification for a user.
 */
function createNotification(User $user, array $data = []): DatabaseNotification
{
    return DatabaseNotification::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'type' => 'App\\Notifications\\InAppNotification',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => array_merge([
            'category' => 'new_section',
            'group_key' => 'test_'.now()->timestamp,
            'title' => 'Test Notification',
            'message' => 'This is a test notification',
            'url' => '/contacts',
            'count' => 1,
            'icon' => 'o-bell',
        ], $data),
    ]);
}
