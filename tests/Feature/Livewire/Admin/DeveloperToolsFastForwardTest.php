<?php

use App\Livewire\Admin\DeveloperTools;
use App\Models\Event;
use App\Models\User;
use App\Services\DeveloperClockService;
use Carbon\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Create permission first
    Permission::create(['name' => 'manage-settings']);

    // Create admin user and grant permission
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo('manage-settings');
});

test('fast forwards time to next upcoming event', function () {
    actingAs($this->admin);

    // Create events: one in the past, one upcoming
    $pastEvent = Event::factory()->create([
        'name' => 'Past Event',
        'start_time' => now()->subDays(10),
        'end_time' => now()->subDays(9),
    ]);

    $upcomingEvent = Event::factory()->create([
        'name' => 'Next Event',
        'start_time' => now()->addDays(30),
        'end_time' => now()->addDays(32),
    ]);

    // Fast forward to the next event
    Livewire::test(DeveloperTools::class)
        ->call('fastForwardToNextEvent')
        ->assertSuccessful()
        ->assertSet('fakeDate', Carbon::parse($upcomingEvent->start_time)->format('Y-m-d'))
        ->assertSet('fakeTime', Carbon::parse($upcomingEvent->start_time)->format('H:i'))
        ->assertSet('timeFrozen', true);

    // Verify the DeveloperClockService was updated
    $clockService = app(DeveloperClockService::class);
    $fakeTime = $clockService->getFakeTime();

    expect($fakeTime)->not->toBeNull()
        ->and($fakeTime->toDateTimeString())->toBe(Carbon::parse($upcomingEvent->start_time)->toDateTimeString())
        ->and($clockService->isFrozen())->toBeTrue();
});

test('shows error when no upcoming events exist', function () {
    actingAs($this->admin);

    // Create only past events
    Event::factory()->create([
        'start_time' => now()->subDays(10),
        'end_time' => now()->subDays(9),
    ]);

    Livewire::test(DeveloperTools::class)
        ->call('fastForwardToNextEvent')
        ->assertSuccessful();

    // Verify the DeveloperClockService was NOT updated
    $clockService = app(DeveloperClockService::class);
    $fakeTime = $clockService->getFakeTime();

    expect($fakeTime)->toBeNull();
});

test('fast forwards to earliest upcoming event when multiple exist', function () {
    actingAs($this->admin);

    // Create multiple upcoming events
    $laterEvent = Event::factory()->create([
        'name' => 'Later Event',
        'start_time' => now()->addDays(60),
        'end_time' => now()->addDays(62),
    ]);

    $earlierEvent = Event::factory()->create([
        'name' => 'Earlier Event',
        'start_time' => now()->addDays(30),
        'end_time' => now()->addDays(32),
    ]);

    Livewire::test(DeveloperTools::class)
        ->call('fastForwardToNextEvent')
        ->assertSuccessful()
        ->assertSet('fakeDate', Carbon::parse($earlierEvent->start_time)->format('Y-m-d'))
        ->assertSet('fakeTime', Carbon::parse($earlierEvent->start_time)->format('H:i'));

    // Verify the clock service has the correct time
    $clockService = app(DeveloperClockService::class);
    $fakeTime = $clockService->getFakeTime();

    expect($fakeTime->toDateTimeString())->toBe(Carbon::parse($earlierEvent->start_time)->toDateTimeString());
});

test('logs audit entry when fast forwarding', function () {
    actingAs($this->admin);

    $upcomingEvent = Event::factory()->create([
        'name' => 'Test Event',
        'start_time' => now()->addDays(30),
        'end_time' => now()->addDays(32),
    ]);

    Livewire::test(DeveloperTools::class)
        ->call('fastForwardToNextEvent')
        ->assertSuccessful();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->admin->id,
        'action' => 'developer.quick_action.fast_forward_event',
    ]);
});

test('requires manage-settings permission', function () {
    $user = User::factory()->create();
    // No permission granted

    actingAs($user);

    Livewire::test(DeveloperTools::class)
        ->assertForbidden();
});
