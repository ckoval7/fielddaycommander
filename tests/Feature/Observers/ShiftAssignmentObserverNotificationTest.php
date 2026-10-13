<?php

use App\Enums\NotificationCategory;
use App\Livewire\Profile\UserProfile;
use App\Livewire\Schedule\MyShifts;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::findOrCreate('manage-shifts');
    Permission::findOrCreate('sign-up-shifts');

    $this->manager = User::factory()->create();
    $this->manager->givePermissionTo('manage-shifts');

    $this->bystander = User::factory()->create();

    $this->volunteer = User::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'call_sign' => 'W1JD',
    ]);
    $this->volunteer->givePermissionTo('sign-up-shifts');

    $this->confirmRole = ShiftRole::factory()->create([
        'name' => 'Safety Officer',
        'requires_confirmation' => true,
    ]);

    $this->shift = Shift::factory()->create([
        'event_configuration_id' => $this->confirmRole->event_configuration_id,
        'shift_role_id' => $this->confirmRole->id,
        'start_time' => appNow()->subMinutes(5),
        'end_time' => appNow()->addHour(),
    ]);

    $this->assignment = ShiftAssignment::factory()->create([
        'shift_id' => $this->shift->id,
        'user_id' => $this->volunteer->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
        'checked_in_at' => null,
        'checked_out_at' => null,
        'confirmed_by_user_id' => null,
        'confirmed_at' => null,
    ]);
});

test('volunteer checking in to a confirmation role notifies shift managers only', function () {
    Notification::fake();

    Livewire::actingAs($this->volunteer)
        ->test(MyShifts::class)
        ->call('checkIn', $this->assignment->id);

    Notification::assertSentTo($this->manager, InAppNotification::class, function (InAppNotification $notification) {
        return $notification->category === NotificationCategory::ShiftConfirmation
            && $notification->message === 'Jane Doe (W1JD) checked in as Safety Officer'
            && $notification->url === route('schedule.manage', ['tab' => 'confirmations']);
    });
    Notification::assertNotSentTo([$this->bystander, $this->volunteer], InAppNotification::class);
});

test('no notification for roles that do not require confirmation', function () {
    Notification::fake();

    $this->confirmRole->update(['requires_confirmation' => false]);

    $this->assignment->fresh()->checkIn();

    Notification::assertNothingSent();
});

test('re-checking in after checkout does not notify again', function () {
    $this->assignment->update([
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => appNow()->subMinutes(5),
        'checked_out_at' => appNow(),
    ]);

    Notification::fake();

    $this->assignment->fresh()->checkBackIn();

    Notification::assertNothingSent();
});

test('manager performing the check-in is not notified about their own action', function () {
    $otherManager = User::factory()->create();
    $otherManager->givePermissionTo('manage-shifts');

    Notification::fake();

    $this->actingAs($this->manager);
    $this->assignment->fresh()->checkIn();

    Notification::assertNotSentTo($this->manager, InAppNotification::class);
    Notification::assertSentTo($otherManager, InAppNotification::class);
});

test('managers who opt out of shift confirmations are not notified', function () {
    $this->manager->update([
        'notification_preferences' => ['categories' => ['shift_confirmation' => false]],
    ]);

    Notification::fake();

    $this->assignment->fresh()->checkIn();

    Notification::assertNothingSent();
});

test('shift confirmation toggle only shows for shift managers', function () {
    Livewire::actingAs($this->manager)
        ->test(UserProfile::class)
        ->assertSet('notifyShiftConfirmation', true)
        ->assertSeeHtml('wire:model="notifyShiftConfirmation"');

    Livewire::actingAs($this->volunteer)
        ->test(UserProfile::class)
        ->assertDontSeeHtml('wire:model="notifyShiftConfirmation"');
});
