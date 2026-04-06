# Session Supported Bands Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show which bands a station supports (radio ∩ antenna) as an informational block above the band dropdown in the session setup modal.

**Architecture:** Add a `stationSupportedBands` computed to `StationSelect` that intersects the primary radio's bands with the union of all assigned antenna bands. Update the eager load to include `additionalEquipment.bands`. Display the result as badges (or an info/warning alert) above the band dropdown in the setup modal blade.

**Tech Stack:** Laravel 12, Livewire 4, Tailwind CSS v4, Mary UI components, Pest 4

---

## File Map

| File | Change |
|---|---|
| `app/Livewire/Logging/StationSelect.php` | Add `additionalEquipment.bands` to eager load; add `stationSupportedBands` computed |
| `resources/views/livewire/logging/station-select.blade.php` | Add informational block above band dropdown in setup modal |
| `tests/Feature/Livewire/Logging/StationSelectTest.php` | Add 4 new tests for `stationSupportedBands` |

---

### Task 1: Write failing tests for `stationSupportedBands`

**Files:**
- Modify: `tests/Feature/Livewire/Logging/StationSelectTest.php`

The test file uses a `beforeEach` that creates `$this->user`, `$this->band`, and `$this->mode`. To test `stationSupportedBands`, the component must have an active event (so `$this->stations` is populated) and `selectedStationId` must be set.

Note: `stationSupportedBands` is a Livewire computed, accessed via `->get('stationSupportedBands')` on the `Livewire::test()` component object.

- [ ] **Step 1: Add the four new tests**

Append to `tests/Feature/Livewire/Logging/StationSelectTest.php`:

```php
test('stationSupportedBands returns intersecting bands when radio and antenna share bands', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $band40m = Band::create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.15,
        'allowed_fd' => true, 'sort_order' => 3,
    ]);

    $radio = Equipment::factory()->create(['type' => 'radio']);
    $radio->bands()->attach([$this->band->id, $band40m->id]); // radio supports 20m and 40m

    $antenna = Equipment::factory()->create(['type' => 'antenna']);
    $antenna->bands()->attach([$this->band->id]); // antenna supports 20m only

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);
    $station->additionalEquipment()->attach($antenna->id, ['event_id' => $event->id]);

    $result = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->get('stationSupportedBands');

    expect($result)->not->toBeNull()
        ->and($result->pluck('id')->toArray())->toEqual([$this->band->id]); // only 20m
});

test('stationSupportedBands returns null when station has no radio', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => null,
    ]);

    $result = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->get('stationSupportedBands');

    expect($result)->toBeNull();
});

test('stationSupportedBands returns null when station has no antennas assigned', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $radio = Equipment::factory()->create(['type' => 'radio']);
    $radio->bands()->attach($this->band->id);

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);
    // No additionalEquipment attached

    $result = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->get('stationSupportedBands');

    expect($result)->toBeNull();
});

test('stationSupportedBands returns empty collection when radio and antenna share no bands', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $band40m = Band::create([
        'name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.15,
        'allowed_fd' => true, 'sort_order' => 3,
    ]);

    $radio = Equipment::factory()->create(['type' => 'radio']);
    $radio->bands()->attach($this->band->id); // radio: 20m

    $antenna = Equipment::factory()->create(['type' => 'antenna']);
    $antenna->bands()->attach($band40m->id); // antenna: 40m only — no overlap

    $station = Station::factory()->create([
        'event_configuration_id' => $config->id,
        'radio_equipment_id' => $radio->id,
    ]);
    $station->additionalEquipment()->attach($antenna->id, ['event_id' => $event->id]);

    $result = Livewire::test(StationSelect::class)
        ->set('selectedStationId', $station->id)
        ->get('stationSupportedBands');

    expect($result)->not->toBeNull()
        ->and($result)->toHaveCount(0);
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter="stationSupportedBands"
```

Expected: 4 failures — `stationSupportedBands` does not exist yet.

---

### Task 2: Add eager load and implement `stationSupportedBands` computed

**Files:**
- Modify: `app/Livewire/Logging/StationSelect.php`

- [ ] **Step 1: Add `additionalEquipment.bands` to the stations eager load**

In the `stations()` computed (around line 79), update the `with()` call:

```php
return $event->eventConfiguration->stations()
    ->with([
        'primaryRadio.bands',
        'additionalEquipment.bands',
        'operatingSessions' => function ($query) {
            $query->active()->with(['operator', 'band', 'mode'])->latest();
        },
    ])
    ->orderBy('name')
    ->get()
```

- [ ] **Step 2: Add the `stationSupportedBands` computed property**

Add this method to `StationSelect` after the `bandWarning` computed (around line 165):

```php
#[Computed]
public function stationSupportedBands(): ?\Illuminate\Support\Collection
{
    if (! $this->selectedStationId) {
        return null;
    }

    $station = $this->stations->firstWhere('id', $this->selectedStationId);
    if (! $station) {
        return null;
    }

    if (! $station->primaryRadio) {
        return null;
    }

    $antennas = $station->additionalEquipment->where('type', 'antenna');
    if ($antennas->isEmpty()) {
        return null;
    }

    $radioBandIds = $station->primaryRadio->bands->pluck('id');
    $antennaBandIds = $antennas->flatMap(fn ($antenna) => $antenna->bands->pluck('id'))->unique();
    $intersectingBandIds = $radioBandIds->intersect($antennaBandIds);

    return $station->primaryRadio->bands
        ->filter(fn ($band) => $intersectingBandIds->contains($band->id))
        ->values();
}
```

- [ ] **Step 3: Run tests to confirm they pass**

```bash
php artisan test --compact --filter="stationSupportedBands"
```

Expected: 4 passing.

- [ ] **Step 4: Run pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Logging/StationSelect.php tests/Feature/Livewire/Logging/StationSelectTest.php
git commit -m "feat(logging): add stationSupportedBands computed to session setup"
```

---

### Task 3: Add informational band block to setup modal blade

**Files:**
- Modify: `resources/views/livewire/logging/station-select.blade.php`

The setup modal already defines `$selectedStation` at line 145. The new block goes directly **above** the `<x-select label="Band"` element (currently line 106).

- [ ] **Step 1: Add the supported bands block above the band dropdown**

Replace this in the modal `<div class="space-y-4">`:

```blade
        <x-select
            label="Band"
            wire:model.live="selectedBandId"
```

With:

```blade
        @php $supportedBands = $this->stationSupportedBands; @endphp
        @if($supportedBands === null)
            @php $stationForBands = $this->stations?->firstWhere('id', $selectedStationId); @endphp
            @if($stationForBands && ! $stationForBands->primaryRadio)
                <x-alert icon="o-information-circle" class="alert-info text-sm">
                    No radio assigned — band compatibility unknown.
                </x-alert>
            @elseif($stationForBands)
                <x-alert icon="o-information-circle" class="alert-info text-sm">
                    No antennas assigned — band compatibility unknown.
                </x-alert>
            @endif
        @elseif($supportedBands->isEmpty())
            <x-alert icon="o-exclamation-triangle" class="alert-warning text-sm">
                No bands are supported by both the radio and antennas at this station.
            </x-alert>
        @else
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-base-content/70">Station supports:</span>
                @foreach($supportedBands as $supportedBand)
                    <x-badge :value="$supportedBand->name" class="badge-outline badge-sm" />
                @endforeach
            </div>
        @endif

        <x-select
            label="Band"
            wire:model.live="selectedBandId"
```

- [ ] **Step 2: Run the full StationSelect test suite**

```bash
php artisan test --compact tests/Feature/Livewire/Logging/StationSelectTest.php
```

Expected: all passing.

- [ ] **Step 3: Run pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/livewire/logging/station-select.blade.php
git commit -m "feat(logging): show supported bands in session setup modal"
```
