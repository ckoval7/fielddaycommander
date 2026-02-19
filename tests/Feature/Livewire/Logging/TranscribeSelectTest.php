<?php

use App\Livewire\Logging\TranscribeSelect;
use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);
});

test('requires authentication', function () {
    $this->get(route('logging.transcribe.select'))
        ->assertRedirect();
});

test('requires log-contacts permission', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    Livewire::test(TranscribeSelect::class)
        ->assertForbidden();
});

test('renders successfully during active event', function () {
    $this->actingAs($this->user);

    Event::factory()->has(
        EventConfiguration::factory(),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    Livewire::test(TranscribeSelect::class)
        ->assertStatus(200);
});

test('shows no event message when no active event', function () {
    $this->actingAs($this->user);

    // No event exists — archived state
    Livewire::test(TranscribeSelect::class)
        ->assertSee('No event available for transcription');
});

test('shows station cards with equipment details', function () {
    $this->actingAs($this->user);

    $radio = Equipment::factory()->create(['make' => 'Icom', 'model' => '7300']);

    Event::factory()->has(
        EventConfiguration::factory()->has(
            Station::factory()->state(['radio_equipment_id' => $radio->id, 'name' => 'Alpha']),
            'stations'
        ),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    Livewire::test(TranscribeSelect::class)
        ->assertSee('Alpha')
        ->assertSee('Icom')
        ->assertSee('7300');
});

test('shows GOTA badge on gota stations', function () {
    $this->actingAs($this->user);

    Event::factory()->has(
        EventConfiguration::factory()->has(
            Station::factory()->state(['is_gota' => true, 'name' => 'GOTA Station']),
            'stations'
        ),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    Livewire::test(TranscribeSelect::class)
        ->assertSee('GOTA');
});
