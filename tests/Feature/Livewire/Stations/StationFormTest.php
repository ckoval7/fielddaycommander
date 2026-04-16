<?php

use App\Enums\PowerSource;
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
        ->assertSessionHas('toast');

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

test('allows saving station with power exceeding event limit', function () {
    // Station can be saved with power above the limit — it's a warning, not a block
    Livewire::test(StationForm::class)
        ->set('name', 'High Power Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->set('max_power_watts', 200) // Exceeds 150W limit
        ->call('save')
        ->assertHasNoErrors(['max_power_watts'])
        ->assertSessionHas('toast');

    $this->assertDatabaseHas('stations', [
        'name' => 'High Power Station',
        'max_power_watts' => 200,
    ]);
});

test('maxPowerLimit falls back to event config power when operating class has no limit', function () {
    $noLimitClass = OperatingClass::create([
        'event_type_id' => $this->event->event_type_id,
        'code' => '1B',
        'name' => 'Class 1B',
        'allows_gota' => false,
        'max_power_watts' => null,
        'requires_emergency_power' => true,
    ]);

    $newEvent = Event::factory()->create([
        'event_type_id' => $this->event->event_type_id,
        'is_active' => true,
    ]);

    $configWith5W = EventConfiguration::factory()->create([
        'event_id' => $newEvent->id,
        'operating_class_id' => $noLimitClass->id,
        'section_id' => Section::first()->id,
        'max_power_watts' => 5,
    ]);

    // When operating class has no power limit, warning uses event config power
    Livewire::test(StationForm::class)
        ->set('event_configuration_id', $configWith5W->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->set('max_power_watts', 5)
        ->assertSee('within')
        ->assertDontSee('Scoring impact')
        ->set('max_power_watts', 100)
        ->assertSee('Scoring impact');
});

test('power warning displays when station exceeds event config power', function () {
    $noLimitClass = OperatingClass::create([
        'event_type_id' => $this->event->event_type_id,
        'code' => '1C',
        'name' => 'Class 1C',
        'allows_gota' => false,
        'max_power_watts' => null,
        'requires_emergency_power' => false,
    ]);

    $newEvent = Event::factory()->create([
        'event_type_id' => $this->event->event_type_id,
        'is_active' => true,
    ]);

    $configWith5W = EventConfiguration::factory()->create([
        'event_id' => $newEvent->id,
        'operating_class_id' => $noLimitClass->id,
        'section_id' => Section::first()->id,
        'max_power_watts' => 5,
    ]);

    Livewire::test(StationForm::class)
        ->set('event_configuration_id', $configWith5W->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->set('max_power_watts', 100)
        ->assertSee('Scoring impact');
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

test('hides radios already assigned to other stations in the same event', function () {
    // Assign the default radio to an existing station
    Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Existing Station',
    ]);

    // Create an unassigned radio
    $freeRadio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Kenwood',
        'model' => 'TS-590SG',
    ]);

    $component = Livewire::test(StationForm::class)
        ->set('event_configuration_id', $this->eventConfig->id);

    $component->call('searchRadios', '');

    $radioIds = $component->availableRadios->pluck('id')->all();
    expect($radioIds)->toContain($freeRadio->id)
        ->and($radioIds)->not->toContain($this->radio->id);
});

test('shows current stations own radio in the list when editing', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'My Station',
    ]);

    $component = Livewire::test(StationForm::class, ['station' => $station]);
    $component->call('searchRadios', '');

    $radioIds = $component->availableRadios->pluck('id')->all();
    expect($radioIds)->toContain($this->radio->id);
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

test('prevents assigning same radio to multiple stations in same event', function () {
    // Create a station with the radio
    Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Existing Station',
    ]);

    // Try to create another station with the same radio in the same event
    Livewire::test(StationForm::class)
        ->set('name', 'New Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->call('save')
        ->assertHasErrors(['radio_equipment_id' => 'unique']);
});

test('allows same radio on stations in different events', function () {
    // Create a station with the radio in the current event
    Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Existing Station',
    ]);

    // Create a different event
    $otherEvent = Event::factory()->create([
        'event_type_id' => $this->event->event_type_id,
        'is_active' => true,
    ]);
    $otherConfig = EventConfiguration::factory()->create([
        'event_id' => $otherEvent->id,
        'section_id' => Section::first()->id,
        'operating_class_id' => OperatingClass::first()->id,
    ]);

    // Same radio in a different event should be fine
    Livewire::test(StationForm::class)
        ->set('name', 'Station In Other Event')
        ->set('event_configuration_id', $otherConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->call('save')
        ->assertHasNoErrors(['radio_equipment_id']);
});

test('allows updating station without radio uniqueness error on itself', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'My Station',
    ]);

    // Editing the same station, keeping the same radio, should not trigger uniqueness error
    Livewire::test(StationForm::class, ['station' => $station])
        ->set('name', 'My Updated Station')
        ->call('save')
        ->assertHasNoErrors(['radio_equipment_id']);
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

test('selecting gota clears vhf_only and satellite', function () {
    Livewire::test(StationForm::class)
        ->set('is_vhf_only', true)
        ->set('is_satellite', true)
        ->set('is_gota', true)
        ->assertSet('is_gota', true)
        ->assertSet('is_vhf_only', false)
        ->assertSet('is_satellite', false);
});

test('selecting vhf_only clears gota and satellite', function () {
    Livewire::test(StationForm::class)
        ->set('is_gota', true)
        ->set('is_satellite', true)
        ->set('is_vhf_only', true)
        ->assertSet('is_vhf_only', true)
        ->assertSet('is_gota', false)
        ->assertSet('is_satellite', false);
});

test('selecting satellite clears gota and vhf_only', function () {
    Livewire::test(StationForm::class)
        ->set('is_gota', true)
        ->set('is_vhf_only', true)
        ->set('is_satellite', true)
        ->assertSet('is_satellite', true)
        ->assertSet('is_gota', false)
        ->assertSet('is_vhf_only', false);
});

test('validation rejects multiple station type flags', function () {
    $newRadio = Equipment::factory()->create(['type' => 'radio']);

    // Create a station directly with invalid state, then try to update it
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $newRadio->id,
        'name' => 'Bad Station',
        'is_vhf_only' => true,
        'is_satellite' => true,
    ]);

    // Loading the station should have both flags; saving should fail validation
    $component = Livewire::test(StationForm::class, ['station' => $station]);

    // Confirm both flags loaded from DB
    expect($component->get('is_vhf_only'))->toBeTrue()
        ->and($component->get('is_satellite'))->toBeTrue();

    $component->call('save')
        ->assertHasErrors(['is_vhf_only', 'is_satellite']);
});

test('can create station with power source', function () {
    $radio = Equipment::factory()->create(['type' => 'radio']);

    Livewire::test(StationForm::class)
        ->set('name', 'Solar Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $radio->id)
        ->set('power_source', 'solar')
        ->set('power_source_description', '200W panel array')
        ->call('save');

    $this->assertDatabaseHas('stations', [
        'name' => 'Solar Station',
        'power_source' => 'solar',
        'power_source_description' => '200W panel array',
    ]);
});

test('can update station power source', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'power_source' => PowerSource::Generator,
    ]);

    Livewire::test(StationForm::class, ['station' => $station])
        ->set('power_source', 'battery')
        ->call('save');

    expect($station->fresh()->power_source)->toBe(PowerSource::Battery);
});

test('power source is optional', function () {
    $radio = Equipment::factory()->create(['type' => 'radio']);

    Livewire::test(StationForm::class)
        ->set('name', 'No Power Source')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $radio->id)
        ->set('power_source', null)
        ->call('save');

    $this->assertDatabaseHas('stations', [
        'name' => 'No Power Source',
        'power_source' => null,
    ]);
});

test('allows reusing a deleted stations radio for a new station', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Old Station',
    ]);

    $station->delete();

    Livewire::test(StationForm::class)
        ->set('name', 'New Station')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $this->radio->id)
        ->call('save')
        ->assertHasNoErrors(['radio_equipment_id']);
});

test('allows reusing a deleted stations name for a new station', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Reusable Name',
    ]);

    $station->delete();

    $newRadio = Equipment::factory()->create(['type' => 'radio']);

    Livewire::test(StationForm::class)
        ->set('name', 'Reusable Name')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $newRadio->id)
        ->call('save')
        ->assertHasNoErrors(['name']);
});

test('rejects invalid power source value', function () {
    $radio = Equipment::factory()->create(['type' => 'radio']);

    Livewire::test(StationForm::class)
        ->set('name', 'Bad Power')
        ->set('event_configuration_id', $this->eventConfig->id)
        ->set('radio_equipment_id', $radio->id)
        ->set('power_source', 'nuclear')
        ->call('save')
        ->assertHasErrors(['power_source']);
});
