<?php

use App\Livewire\Stations\StationForm;
use App\Models\Equipment;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    // Create permission if it doesn't exist
    Permission::firstOrCreate(['name' => 'manage-stations']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('manage-stations');
    $this->actingAs($this->user);

    // Create event and configuration using direct model creation
    $eventType = EventType::create([
        'name' => 'Field Day',
        'code' => 'FD',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);

    $section = Section::create([
        'code' => 'AK',
        'name' => 'Alaska',
        'region' => 'KL7',
        'country' => 'US',
        'is_active' => true,
    ]);

    $operatingClass = OperatingClass::create([
        'event_type_id' => $eventType->id,
        'code' => '2A',
        'name' => 'Class 2A',
        'allows_gota' => true,
        'max_power_watts' => 150,
        'requires_emergency_power' => false,
    ]);

    $this->event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'is_active' => true,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'section_id' => $section->id,
        'operating_class_id' => $operatingClass->id,
    ]);

    // Create radio equipment
    $this->radio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'power_output_watts' => 100,
    ]);
});

test('component can render for create mode', function () {
    Livewire::test(StationForm::class)
        ->assertStatus(200)
        ->assertSee('Create Station')
        ->assertSee('Basic Information');
});

test('component can render for edit mode', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Test Station',
    ]);

    Livewire::test(StationForm::class, ['station' => $station])
        ->assertStatus(200)
        ->assertSee('Edit Station')
        ->assertSet('name', 'Test Station');
});

test('can create a station', function () {
    Livewire::test(StationForm::class)
        ->set('name', 'Phone Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->set('max_power_watts', 100)
        ->set('is_gota', false)
        ->set('is_vhf_only', false)
        ->set('is_satellite', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $this->assertDatabaseHas('stations', [
        'name' => 'Phone Station',
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'max_power_watts' => 100,
    ]);
});

test('can update a station', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Old Name',
        'max_power_watts' => 50,
    ]);

    Livewire::test(StationForm::class, ['station' => $station])
        ->set('name', 'New Name')
        ->set('max_power_watts', 100)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $this->assertDatabaseHas('stations', [
        'id' => $station->id,
        'name' => 'New Name',
        'max_power_watts' => 100,
    ]);
});

test('validates required fields', function () {
    Livewire::test(StationForm::class)
        ->set('name', '')
        ->set('event_configuration_id', null)
        ->set('radio_equipment_id', null)
        ->call('save')
        ->assertHasErrors([
            'name' => 'required',
            'event_configuration_id' => 'required',
            'radio_equipment_id' => 'required',
        ]);
});

test('validates station name uniqueness within event', function () {
    Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Duplicate Station',
    ]);

    Livewire::test(StationForm::class)
        ->set('name', 'Duplicate Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->call('save')
        ->assertHasErrors(['name' => 'unique']);
});

test('prevents multiple gota stations for same event', function () {
    // Create existing GOTA station
    Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'is_gota' => true,
    ]);

    // Try to create another GOTA station
    $newRadio = Equipment::factory()->create(['type' => 'radio']);

    Livewire::test(StationForm::class)
        ->set('name', 'Another GOTA')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $newRadio->id)
        ->set('is_gota', true)
        ->call('save')
        ->assertHasErrors(['is_gota']);
});

test('allows gota station only if operating class allows it', function () {
    // Create operating class that doesn't allow GOTA
    $noGotaClass = OperatingClass::create([
        'event_type_id' => $this->event->event_type_id,
        'code' => '1E',
        'name' => 'Class 1E',
        'allows_gota' => false,
        'max_power_watts' => 150,
        'requires_emergency_power' => true,
    ]);

    $this->eventConfig->update(['operating_class_id' => $noGotaClass->id]);

    Livewire::test(StationForm::class)
        ->set('name', 'GOTA Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->set('is_gota', true)
        ->call('save')
        ->assertHasErrors(['is_gota']);
});

test('validates power output range', function () {
    Livewire::test(StationForm::class)
        ->set('name', 'Test Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->set('max_power_watts', 0)
        ->call('save')
        ->assertHasErrors(['max_power_watts' => 'min']);

    Livewire::test(StationForm::class)
        ->set('name', 'Test Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->set('max_power_watts', 6000)
        ->call('save')
        ->assertHasErrors(['max_power_watts' => 'max']);
});

test('warns when power exceeds operating class limit', function () {
    Livewire::test(StationForm::class)
        ->set('name', 'High Power Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->set('max_power_watts', 200) // Exceeds 150W limit
        ->call('save')
        ->assertHasErrors(['max_power_watts']);
});

test('auto-populates max power from selected radio', function () {
    Livewire::test(StationForm::class)
        ->set('radio_equipment_id', $this->radio->id)
        ->assertSet('max_power_watts', 100);
});

test('searches radios by make and model', function () {
    Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);

    $component = Livewire::test(StationForm::class);

    $component->call('searchRadios', 'Icom');

    expect($component->availableRadios->count())->toBeGreaterThan(0)
        ->and($component->availableRadios->pluck('name')->join(' '))->toContain('Icom');
});

test('requires manage-stations permission', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    Livewire::test(StationForm::class)
        ->assertForbidden();
});

test('emits station-saved event on success', function () {
    Livewire::test(StationForm::class)
        ->set('name', 'Phone Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->call('save')
        ->assertDispatched('station-saved');
});

test('defaults to active event when creating station', function () {
    Livewire::test(StationForm::class)
        ->assertSet('event_configuration_id', $this->eventConfig->id);
});

test('clears gota flag when event does not allow gota', function () {
    $noGotaClass = OperatingClass::create([
        'event_type_id' => $this->event->event_type_id,
        'code' => '1D',
        'name' => 'Class 1D',
        'allows_gota' => false,
        'max_power_watts' => 150,
        'requires_emergency_power' => false,
    ]);

    // Create a new event for this config to avoid unique constraint violation
    $newEvent = Event::factory()->create([
        'event_type_id' => $this->event->event_type_id,
        'is_active' => true,
    ]);

    $newEventConfig = EventConfiguration::factory()->create([
        'event_id' => $newEvent->id,
        'operating_class_id' => $noGotaClass->id,
        'section_id' => Section::first()->id,
    ]);

    Livewire::test(StationForm::class)
        ->set('is_gota', true)
        ->set('event_configuration_id', $newEventConfig->id)
        ->assertSet('is_gota', false);
});
