<?php

use App\Enums\StationUnavailableReason;
use App\Livewire\Stations\StationsList;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\Setting;
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::create(['name' => 'view-stations']);
    Permission::create(['name' => 'manage-stations']);

    $operatorRole = Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    $operatorRole->givePermissionTo('view-stations');

    $captainRole = Role::create(['name' => 'Station Captain', 'guard_name' => 'web']);
    $captainRole->givePermissionTo(['view-stations', 'manage-stations']);

    $this->operator = User::factory()->create();
    $this->operator->assignRole($operatorRole);

    $this->captain = User::factory()->create();
    $this->captain->assignRole($captainRole);

    $this->event = Event::factory()->create();
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    Setting::set('active_event_id', $this->event->id);

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'HF Phone',
    ]);
});

test('station captain can mark a station unavailable with a reason and note', function () {
    $this->actingAs($this->captain);

    Livewire::test(StationsList::class)
        ->call('openUnavailableModal', $this->station->id)
        ->assertSet('showUnavailableModal', true)
        ->set('unavailableReason', StationUnavailableReason::EquipmentFailure->value)
        ->set('unavailableNote', 'Rig will not key up')
        ->call('markUnavailable')
        ->assertHasNoErrors()
        ->assertSet('showUnavailableModal', false)
        ->assertDispatched('toast');

    $this->station->refresh();

    expect($this->station->isUnavailable())->toBeTrue()
        ->and($this->station->unavailable_reason)->toBe(StationUnavailableReason::EquipmentFailure)
        ->and($this->station->unavailable_note)->toBe('Rig will not key up')
        ->and($this->station->unavailable_since)->not->toBeNull()
        ->and($this->station->unavailable_by_user_id)->toBe($this->captain->id)
        ->and($this->station->operatingStatus())->toBe('unavailable');

    expect(AuditLog::where('action', 'station.marked_unavailable')->where('auditable_id', $this->station->id)->exists())
        ->toBeTrue();
});

test('marking a station unavailable requires a valid reason', function () {
    $this->actingAs($this->captain);

    Livewire::test(StationsList::class)
        ->call('openUnavailableModal', $this->station->id)
        ->call('markUnavailable')
        ->assertHasErrors(['unavailableReason' => 'required'])
        ->set('unavailableReason', 'not-a-reason')
        ->call('markUnavailable')
        ->assertHasErrors(['unavailableReason'])
        ->set('unavailableReason', 'weather')
        ->call('markUnavailable')
        ->assertHasErrors(['unavailableReason']);

    expect($this->station->refresh()->isUnavailable())->toBeFalse();
});

test('only station-level reasons are offered', function () {
    expect(array_column(StationUnavailableReason::options(), 'value'))->toBe([
        'equipment_failure',
        'antenna_problem',
        'maintenance',
        'other',
    ]);
});

test('marking a station unavailable ends its active sessions', function () {
    $this->actingAs($this->captain);

    $session = OperatingSession::factory()->active()->create(['station_id' => $this->station->id]);

    Livewire::test(StationsList::class)
        ->call('openUnavailableModal', $this->station->id)
        ->set('unavailableReason', StationUnavailableReason::EquipmentFailure->value)
        ->call('markUnavailable')
        ->assertHasNoErrors();

    expect($session->refresh()->end_time)->not->toBeNull();
});

test('station captain can return an unavailable station to service', function () {
    $this->actingAs($this->captain);

    $this->station->markUnavailable(StationUnavailableReason::AntennaProblem, 'Dipole came down', $this->captain);

    Livewire::test(StationsList::class)
        ->call('markAvailable', $this->station->id)
        ->assertDispatched('toast');

    $this->station->refresh();

    expect($this->station->isUnavailable())->toBeFalse()
        ->and($this->station->unavailable_note)->toBeNull()
        ->and($this->station->unavailable_since)->toBeNull()
        ->and($this->station->unavailable_by_user_id)->toBeNull()
        ->and($this->station->operatingStatus())->toBe('available');

    expect(AuditLog::where('action', 'station.marked_available')->where('auditable_id', $this->station->id)->exists())
        ->toBeTrue();
});

test('users without manage-stations cannot change station readiness', function () {
    $this->actingAs($this->operator);

    Livewire::test(StationsList::class)
        ->call('openUnavailableModal', $this->station->id)
        ->assertForbidden();

    $this->station->update(['unavailable_reason' => StationUnavailableReason::Maintenance]);

    Livewire::test(StationsList::class)
        ->call('markAvailable', $this->station->id)
        ->assertForbidden();

    expect($this->station->refresh()->isUnavailable())->toBeTrue();
});

test('operators do not see readiness controls', function () {
    $this->actingAs($this->operator);

    Livewire::test(StationsList::class)
        ->assertDontSee('Mark Unavailable')
        ->assertDontSee('Mark Ready');
});

test('station captains see mark unavailable and mark ready controls', function () {
    $this->actingAs($this->captain);

    Station::factory()->unavailable()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Broken Station',
    ]);

    Livewire::test(StationsList::class)
        ->assertSee('Mark Unavailable')
        ->assertSee('Mark Ready');
});

test('unavailable stations show reason, note and count in stats', function () {
    $this->actingAs($this->operator);

    Station::factory()->unavailable(StationUnavailableReason::AntennaProblem, 'Dipole came down')->create([
        'event_configuration_id' => $this->eventConfig->id,
    ]);

    $component = Livewire::test(StationsList::class)
        ->assertSee('Antenna Problem')
        ->assertSee('Dipole came down')
        ->assertSeeHtml('data-station-unavailable');

    expect($component->get('stats')['unavailable'])->toBe(1);
});

test('ready and unavailable scopes filter stations', function () {
    $unavailable = Station::factory()->unavailable()->create(['event_configuration_id' => $this->eventConfig->id]);

    expect(Station::ready()->pluck('id')->all())->toBe([$this->station->id])
        ->and(Station::unavailable()->pluck('id')->all())->toBe([$unavailable->id]);
});
