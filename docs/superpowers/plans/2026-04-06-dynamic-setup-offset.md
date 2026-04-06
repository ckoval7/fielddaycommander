# Dynamic Setup Offset Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded "preceding Friday at 0000Z" setup window calculation with a data-driven `setup_offset_hours` column on `event_types`, so any event type can define its own setup window offset.

**Architecture:** Add `setup_offset_hours` (nullable unsigned integer) to `event_types`. Update `Event::calculateSetupAllowedFrom()` to accept an hours argument and subtract it from `start_time`. Update all callers in `EventForm` to read the offset from the event type instead of checking `code === 'FD'`.

**Tech Stack:** Laravel 12, Livewire 4, Pest 4, MariaDB

---

### Task 1: Migration — add `setup_offset_hours` to `event_types`

**Files:**
- Create: `database/migrations/2026_04_06_000001_add_setup_offset_hours_to_event_types_table.php`

- [ ] **Step 1: Generate the migration**

```bash
php artisan make:migration add_setup_offset_hours_to_event_types_table --no-interaction
```

- [ ] **Step 2: Write the migration**

Replace the generated file contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->unsignedInteger('setup_offset_hours')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn('setup_offset_hours');
        });
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate --no-interaction
```

Expected output includes: `Running migrations... add_setup_offset_hours_to_event_types_table`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_06_000001_add_setup_offset_hours_to_event_types_table.php
git commit -m "feat(event-types): add setup_offset_hours column"
```

---

### Task 2: Update `EventType` model and seeder

**Files:**
- Modify: `app/Models/EventType.php`
- Modify: `database/seeders/EventTypeSeeder.php`

- [ ] **Step 1: Add `setup_offset_hours` to `$fillable` in `EventType`**

In `app/Models/EventType.php`, change:

```php
protected $fillable = [
    'code',
    'name',
    'description',
    'is_active',
];
```

to:

```php
protected $fillable = [
    'code',
    'name',
    'description',
    'is_active',
    'setup_offset_hours',
];
```

- [ ] **Step 2: Update the seeder to set FD = 24, WFD = null**

In `database/seeders/EventTypeSeeder.php`, change the `$eventTypes` array to:

```php
$eventTypes = [
    [
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day - annual emergency preparedness exercise held the 4th full weekend in June',
        'is_active' => true,
        'setup_offset_hours' => 24,
    ],
    [
        'code' => 'WFD',
        'name' => 'Winter Field Day',
        'description' => 'Winter Field Day Association event - held the last full weekend in January',
        'is_active' => true,
        'setup_offset_hours' => null,
    ],
];
```

- [ ] **Step 3: Update existing FD record in the database**

```bash
php artisan tinker --no-interaction --execute="App\Models\EventType::where('code', 'FD')->update(['setup_offset_hours' => 24]);"
```

Expected output: `= 1`

- [ ] **Step 4: Commit**

```bash
git add app/Models/EventType.php database/seeders/EventTypeSeeder.php
git commit -m "feat(event-types): set setup_offset_hours per event type"
```

---

### Task 3: Update `Event::calculateSetupAllowedFrom()`

**Files:**
- Modify: `app/Models/Event.php` (lines 91–103)
- Modify: `tests/Feature/Models/EventTest.php` (lines 122–149)

- [ ] **Step 1: Write failing tests**

In `tests/Feature/Models/EventTest.php`, replace the two existing `calculateSetupAllowedFrom` tests (lines 122–149) with:

```php
test('calculateSetupAllowedFrom subtracts offset hours from start time', function () {
    // 24 hours before Saturday June 28 2025 at 1800Z = Friday June 27 2025 at 1800Z
    $startTime = \Carbon\Carbon::parse('2025-06-28 18:00:00');
    $setupFrom = Event::calculateSetupAllowedFrom($startTime, 24);

    expect($setupFrom->toDateTimeString())->toBe('2025-06-27 18:00:00');
});

test('calculateSetupAllowedFrom with 48 hour offset', function () {
    $startTime = \Carbon\Carbon::parse('2025-06-28 18:00:00');
    $setupFrom = Event::calculateSetupAllowedFrom($startTime, 48);

    expect($setupFrom->toDateTimeString())->toBe('2025-06-26 18:00:00');
});

test('calculateSetupAllowedFrom with 6 hour offset for club meeting', function () {
    $startTime = \Carbon\Carbon::parse('2025-07-08 23:00:00'); // Tuesday 11pm UTC
    $setupFrom = Event::calculateSetupAllowedFrom($startTime, 6);

    expect($setupFrom->toDateTimeString())->toBe('2025-07-08 17:00:00');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="calculateSetupAllowedFrom"
```

Expected: FAIL — method signature mismatch (missing `$offsetHours` parameter)

- [ ] **Step 3: Update the method in `app/Models/Event.php`**

Replace lines 91–103:

```php
/**
 * Calculate setup_allowed_from per ARRL Field Day Rule 3.3:
 * 0000 UTC on the Friday preceding the event start.
 */
public static function calculateSetupAllowedFrom(Carbon $startTime): Carbon
{
    $friday = $startTime->copy()->startOfDay();

    if ($friday->dayOfWeek !== Carbon::FRIDAY) {
        $friday = $friday->previous(Carbon::FRIDAY);
    }

    return $friday;
}
```

with:

```php
/**
 * Calculate setup_allowed_from by subtracting the given offset from the event start time.
 */
public static function calculateSetupAllowedFrom(Carbon $startTime, int $offsetHours): Carbon
{
    return $startTime->copy()->subHours($offsetHours);
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter="calculateSetupAllowedFrom"
```

Expected: 3 tests pass

- [ ] **Step 5: Commit**

```bash
git add app/Models/Event.php tests/Feature/Models/EventTest.php
git commit -m "feat(event): replace Friday-based setup calculation with hour offset"
```

---

### Task 4: Update `EventForm` — create, update, and clone paths

**Files:**
- Modify: `app/Livewire/Events/EventForm.php`

- [ ] **Step 1: Update `setupAllowedFrom` computed property (around line 256)**

Replace:

```php
#[Computed]
public function setupAllowedFrom(): ?string
{
    if (! $this->start_time || ! $this->event_type_id) {
        return null;
    }

    $eventType = EventType::find($this->event_type_id);

    if (! $eventType || $eventType->code !== 'FD') {
        return null;
    }

    return Event::calculateSetupAllowedFrom(Carbon::parse($this->start_time))
        ->format('l, F j, Y \a\t Hi\z');
}
```

with:

```php
#[Computed]
public function setupAllowedFrom(): ?string
{
    if (! $this->start_time || ! $this->event_type_id) {
        return null;
    }

    $eventType = EventType::find($this->event_type_id);

    if (! $eventType || $eventType->setup_offset_hours === null) {
        return null;
    }

    return Event::calculateSetupAllowedFrom(Carbon::parse($this->start_time), $eventType->setup_offset_hours)
        ->format('l, F j, Y \a\t Hi\z');
}
```

- [ ] **Step 2: Update `createEvent` method (around line 474)**

Replace:

```php
'setup_allowed_from' => $eventType?->code === 'FD'
    ? Event::calculateSetupAllowedFrom($startTime)
    : null,
```

with:

```php
'setup_allowed_from' => $eventType?->setup_offset_hours !== null
    ? Event::calculateSetupAllowedFrom($startTime, $eventType->setup_offset_hours)
    : null,
```

- [ ] **Step 3: Update `updateEvent` method (around line 547)**

Replace:

```php
$eventData['setup_allowed_from'] = $eventType?->code === 'FD'
    ? Event::calculateSetupAllowedFrom(Carbon::parse($validated['start_time']))
    : null;
```

with:

```php
$eventData['setup_allowed_from'] = $eventType?->setup_offset_hours !== null
    ? Event::calculateSetupAllowedFrom(Carbon::parse($validated['start_time']), $eventType->setup_offset_hours)
    : null;
```

- [ ] **Step 4: Check for clone path — search for any other callers**

```bash
grep -n "calculateSetupAllowedFrom\|code.*FD\|FD.*code" app/Livewire/Events/EventForm.php
```

If any remaining `code === 'FD'` guards reference setup logic, apply the same `setup_offset_hours !== null` substitution.

- [ ] **Step 5: Run pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Events/EventForm.php
git commit -m "feat(event-form): use setup_offset_hours instead of hardcoded FD code check"
```

---

### Task 5: Tests — EventForm and WFD coverage

**Files:**
- Modify: `tests/Feature/Livewire/Events/EventFormTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/Livewire/Events/EventFormTest.php`:

```php
use App\Models\EventType;

test('creating an FD event sets setup_allowed_from based on offset hours', function () {
    $user = \App\Models\User::factory()->create();
    $fdType = EventType::where('code', 'FD')->first();
    $section = \App\Models\Section::first();
    $class = \App\Models\OperatingClass::where('event_type_id', $fdType->id)->first();

    // FD starts Saturday 1800Z; offset=24 → setup_allowed_from = Friday 1800Z
    Livewire::actingAs($user)
        ->test(\App\Livewire\Events\EventForm::class)
        ->set('event_type_id', $fdType->id)
        ->set('name', 'Test FD 2026')
        ->set('start_time', '2026-06-27 18:00')
        ->set('end_time', '2026-06-28 20:59')
        ->set('callsign', 'W1AW')
        ->set('section_id', $section->id)
        ->set('operating_class_id', $class->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->call('save');

    $event = \App\Models\Event::where('name', 'Test FD 2026')->first();
    expect($event->setup_allowed_from->toDateTimeString())->toBe('2026-06-26 18:00:00');
});

test('creating a WFD event leaves setup_allowed_from null', function () {
    $user = \App\Models\User::factory()->create();
    $wfdType = EventType::where('code', 'WFD')->first();
    $section = \App\Models\Section::first();
    $class = \App\Models\OperatingClass::where('event_type_id', $wfdType->id)->first();

    Livewire::actingAs($user)
        ->test(\App\Livewire\Events\EventForm::class)
        ->set('event_type_id', $wfdType->id)
        ->set('name', 'Test WFD 2027')
        ->set('start_time', '2027-01-30 18:00')
        ->set('end_time', '2027-01-31 20:59')
        ->set('callsign', 'W1AW')
        ->set('section_id', $section->id)
        ->set('operating_class_id', $class->id)
        ->set('transmitter_count', 1)
        ->set('max_power_watts', 100)
        ->call('save');

    $event = \App\Models\Event::where('name', 'Test WFD 2027')->first();
    expect($event->setup_allowed_from)->toBeNull();
});

test('setupAllowedFrom computed property returns null for WFD', function () {
    $user = \App\Models\User::factory()->create();
    $wfdType = EventType::where('code', 'WFD')->first();

    Livewire::actingAs($user)
        ->test(\App\Livewire\Events\EventForm::class)
        ->set('event_type_id', $wfdType->id)
        ->set('start_time', '2027-01-30 18:00')
        ->assertSet('setupAllowedFrom', null);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="EventFormTest"
```

Expected: the three new tests fail (old code still uses `code === 'FD'` check and old method signature)

> Note: If Task 4 is already done, these may pass immediately — that's fine, move to step 4.

- [ ] **Step 3: Run all affected tests to verify everything passes**

```bash
php artisan test --compact --filter="EventFormTest|EventTest|EventContextSelectorTest"
```

Expected: all pass

- [ ] **Step 4: Run pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Livewire/Events/EventFormTest.php
git commit -m "test(event-form): cover setup_offset_hours for FD and WFD event types"
```

---

### Task 6: Final verification

- [ ] **Step 1: Run full affected test suite**

```bash
php artisan test --compact --filter="EventTest|EventFormTest|EventContextSelectorTest"
```

Expected: all pass, no failures

- [ ] **Step 2: Verify the FD event type has the correct offset in the database**

```bash
php artisan tinker --no-interaction --execute="dump(App\Models\EventType::all(['code', 'setup_offset_hours'])->toArray());"
```

Expected:
```
array:2 [
  0 => array:2 ["code" => "FD", "setup_offset_hours" => 24]
  1 => array:2 ["code" => "WFD", "setup_offset_hours" => null]
]
```

- [ ] **Step 3: Run pint across all changed files**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 4: Final commit if pint made changes**

```bash
git add -p
git commit -m "style: pint formatting"
```
