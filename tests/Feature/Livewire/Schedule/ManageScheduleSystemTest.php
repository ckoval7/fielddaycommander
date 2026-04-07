<?php

use App\Livewire\Schedule\ManageSchedule;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'manage-shifts']);

    $this->systemUser = User::factory()->create([
        'call_sign' => User::SYSTEM_CALL_SIGN,
    ]);

    $this->adminUser = User::factory()->create([
        'call_sign' => 'W1ADM',
    ]);
    $this->adminUser->givePermissionTo('manage-shifts');

    $this->event = Event::factory()->create([
        'start_time' => appNow()->subHours(12),
        'end_time' => appNow()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->role = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Station Operator',
        'icon' => 'o-radio',
        'color' => '#6366f1',
    ]);

    $this->shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->addHour(),
        'end_time' => appNow()->addHours(3),
        'is_open' => true,
        'capacity' => 5,
    ]);
});

test('system user is excluded from assignment dropdown', function () {
    $this->actingAs($this->adminUser);

    $component = Livewire::test(ManageSchedule::class)
        ->call('openAssignModal', $this->shift->id);

    $users = $component->get('users');
    $userIds = collect($users)->pluck('id')->all();

    expect($userIds)->not->toContain($this->systemUser->id);
    expect($userIds)->toContain($this->adminUser->id);
});

test('system user cannot be assigned to shifts via server-side guard', function () {
    $this->actingAs($this->adminUser);

    Livewire::test(ManageSchedule::class)
        ->call('openAssignModal', $this->shift->id)
        ->set('assignUserId', $this->systemUser->id)
        ->call('assignUser')
        ->assertDispatched('toast', fn ($name, $params) => str_contains($params['description'], 'SYSTEM account'));

    expect(ShiftAssignment::where('user_id', $this->systemUser->id)->exists())->toBeFalse();
});
