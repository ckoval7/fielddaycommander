# Checkout Confirmation & Re-Check-In Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dynamic early-checkout confirmation dialog and allow users to re-check-in to a checked-out shift while it is still active.

**Architecture:** Add `checkBackIn()` to the `ShiftAssignment` model, add `reCheckIn()` to the `MyShifts` Livewire component, and update the blade view to wire up the confirmation and the new button.

**Tech Stack:** PHP 8.4, Laravel 12, Livewire 4, Tailwind CSS v4, Pest 4

---

## Files

- Modify: `app/Models/ShiftAssignment.php` — add `checkBackIn()` method
- Modify: `app/Livewire/Schedule/MyShifts.php` — add `reCheckIn()` action
- Modify: `resources/views/livewire/schedule/my-shifts.blade.php` — conditional `wire:confirm` on Check Out, "Check In Again" button
- Modify: `tests/Feature/Models/ShiftAssignmentTest.php` — test `checkBackIn()`
- Modify: `tests/Feature/Livewire/Schedule/MyShiftsTest.php` — test `reCheckIn()`

---

### Task 1: `checkBackIn()` on `ShiftAssignment`

**Files:**
- Modify: `app/Models/ShiftAssignment.php`
- Modify: `tests/Feature/Models/ShiftAssignmentTest.php`

- [ ] **Step 1: Write the failing test**

Add inside the existing `describe('Check-in/out', ...)` block in `tests/Feature/Models/ShiftAssignmentTest.php`:

```php
test('checkBackIn reverts to checked_in, clears checked_out_at, and preserves checked_in_at', function () {
    $originalCheckedInAt = now()->subHours(2);
    $assignment = ShiftAssignment::factory()->checkedOut()->create([
        'checked_in_at' => $originalCheckedInAt,
    ]);

    $assignment->checkBackIn();
    $assignment->refresh();

    expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN)
        ->and($assignment->checked_out_at)->toBeNull()
        ->and($assignment->checked_in_at->timestamp)->toBe($originalCheckedInAt->timestamp);
});
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
php artisan test --compact --filter="checkBackIn reverts"
```

Expected: FAIL — "Call to undefined method ... checkBackIn()"

- [ ] **Step 3: Add `checkBackIn()` to `ShiftAssignment`**

Add after `checkOut()` in `app/Models/ShiftAssignment.php`:

```php
/**
 * Revert a checked-out assignment back to checked-in, preserving checked_in_at.
 */
public function checkBackIn(): void
{
    $this->update([
        'status' => self::STATUS_CHECKED_IN,
        'checked_out_at' => null,
    ]);
}
```

- [ ] **Step 4: Run the test to confirm it passes**

```bash
php artisan test --compact --filter="checkBackIn reverts"
```

Expected: PASS

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 6: Commit**

```bash
git add app/Models/ShiftAssignment.php tests/Feature/Models/ShiftAssignmentTest.php
git commit -m "feat: add checkBackIn() to ShiftAssignment model"
```

---

### Task 2: `reCheckIn()` on `MyShifts`

**Files:**
- Modify: `app/Livewire/Schedule/MyShifts.php`
- Modify: `tests/Feature/Livewire/Schedule/MyShiftsTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Livewire/Schedule/MyShiftsTest.php` (at the end, before the closing `}`):

```php
test('user can re-check-in to a checked-out shift while still active', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    $checkedInAt = appNow()->subHour();
    $assignment = ShiftAssignment::factory()->checkedOut()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'checked_in_at' => $checkedInAt,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->call('reCheckIn', $assignment->id)
        ->assertDispatched('toast', title: 'Success', description: 'You have checked back in.');

    $assignment->refresh();
    expect($assignment->status)->toBe(ShiftAssignment::STATUS_CHECKED_IN)
        ->and($assignment->checked_out_at)->toBeNull()
        ->and($assignment->checked_in_at->timestamp)->toBe($checkedInAt->timestamp);
});

test('user cannot re-check-in after shift has ended', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHours(4),
        'end_time' => appNow()->subHours(1),
    ]);

    $assignment = ShiftAssignment::factory()->checkedOut()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user);

    Livewire::test(MyShifts::class)
        ->call('reCheckIn', $assignment->id)
        ->assertDispatched('toast', title: 'Too Late');

    expect($assignment->fresh()->status)->toBe(ShiftAssignment::STATUS_CHECKED_OUT);
});

test('user cannot re-check-in to another users assignment', function () {
    $otherUser = User::factory()->create();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->role->id,
        'start_time' => appNow()->subHour(),
        'end_time' => appNow()->addHour(),
    ]);

    $assignment = ShiftAssignment::factory()->checkedOut()->create([
        'shift_id' => $shift->id,
        'user_id' => $otherUser->id,
    ]);

    $this->actingAs($this->user);

    expect(fn () => Livewire::test(MyShifts::class)
        ->call('reCheckIn', $assignment->id)
    )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
```

- [ ] **Step 2: Run the tests to confirm they fail**

```bash
php artisan test --compact --filter="re-check-in"
```

Expected: FAIL — "Call to undefined method ... reCheckIn()"

- [ ] **Step 3: Add `reCheckIn()` to `MyShifts`**

Add after `checkOut()` in `app/Livewire/Schedule/MyShifts.php`:

```php
/**
 * Re-check-in to a checked-out shift, only while the shift is still active.
 */
public function reCheckIn(int $assignmentId): void
{
    $assignment = ShiftAssignment::where('id', $assignmentId)
        ->where('user_id', Auth::id())
        ->where('status', ShiftAssignment::STATUS_CHECKED_OUT)
        ->firstOrFail();

    if (! $assignment->shift->is_current) {
        $this->dispatch('toast', title: 'Too Late', description: 'This shift has already ended.', icon: 'o-clock', css: 'alert-warning');

        return;
    }

    $assignment->checkBackIn();

    unset($this->currentShifts);
    unset($this->upcomingShifts);
    unset($this->pastShifts);

    $this->dispatch('toast', title: 'Success', description: 'You have checked back in.', icon: 'o-check-circle', css: 'alert-success');
}
```

- [ ] **Step 4: Run the tests to confirm they pass**

```bash
php artisan test --compact --filter="re-check-in"
```

Expected: PASS (3 tests)

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Schedule/MyShifts.php tests/Feature/Livewire/Schedule/MyShiftsTest.php
git commit -m "feat: add reCheckIn() action to MyShifts component"
```

---

### Task 3: Update the view

**Files:**
- Modify: `resources/views/livewire/schedule/my-shifts.blade.php`

- [ ] **Step 1: Replace the actions div in the Current Shifts loop**

In `resources/views/livewire/schedule/my-shifts.blade.php`, find the actions div inside the Current Shifts `@foreach` (around line 114). Replace the entire block from `{{-- Actions --}}` through the closing `</div>`:

Old:
```blade
                                        {{-- Actions --}}
                                        <div class="flex gap-2">
                                            @if($assignment->status === \App\Models\ShiftAssignment::STATUS_SCHEDULED)
                                                @if($shift->can_check_in)
                                                    <x-button
                                                        label="Check In"
                                                        icon="o-arrow-right-on-rectangle"
                                                        class="btn-primary btn-sm"
                                                        wire:click="checkIn({{ $assignment->id }})"
                                                        spinner="checkIn"
                                                    />
                                                @else
                                                    <x-badge value="Check-in opens {{ toLocalTime($shift->start_time->copy()->subMinutes(15))->format('g:i A T') }}" class="badge-ghost badge-sm" />
                                                @endif
                                            @elseif($assignment->status === \App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                                <x-button
                                                    label="Check Out"
                                                    icon="o-arrow-left-on-rectangle"
                                                    class="btn-warning btn-sm"
                                                    wire:click="checkOut({{ $assignment->id }})"
                                                    spinner="checkOut"
                                                />
                                            @endif
                                        </div>
```

New:
```blade
                                        {{-- Actions --}}
                                        <div class="flex gap-2">
                                            @if($assignment->status === \App\Models\ShiftAssignment::STATUS_SCHEDULED)
                                                @if($shift->can_check_in)
                                                    <x-button
                                                        label="Check In"
                                                        icon="o-arrow-right-on-rectangle"
                                                        class="btn-primary btn-sm"
                                                        wire:click="checkIn({{ $assignment->id }})"
                                                        spinner="checkIn"
                                                    />
                                                @else
                                                    <x-badge value="Check-in opens {{ toLocalTime($shift->start_time->copy()->subMinutes(15))->format('g:i A T') }}" class="badge-ghost badge-sm" />
                                                @endif
                                            @elseif($assignment->status === \App\Models\ShiftAssignment::STATUS_CHECKED_IN)
                                                @php $minutesLeft = (int) appNow()->diffInMinutes($shift->end_time); @endphp
                                                <x-button
                                                    label="Check Out"
                                                    icon="o-arrow-left-on-rectangle"
                                                    class="btn-warning btn-sm"
                                                    wire:click="checkOut({{ $assignment->id }})"
                                                    @if(appNow()->isBefore($shift->end_time))
                                                        wire:confirm="You still have {{ $minutesLeft }} {{ $minutesLeft === 1 ? 'minute' : 'minutes' }} left in this shift. Are you sure you want to check out?"
                                                    @endif
                                                    spinner="checkOut"
                                                />
                                            @elseif($assignment->status === \App\Models\ShiftAssignment::STATUS_CHECKED_OUT)
                                                <x-button
                                                    label="Check In Again"
                                                    icon="o-arrow-right-on-rectangle"
                                                    class="btn-ghost btn-sm"
                                                    wire:click="reCheckIn({{ $assignment->id }})"
                                                    spinner="reCheckIn"
                                                />
                                            @endif
                                        </div>
```

- [ ] **Step 2: Run the full MyShifts test file to confirm nothing broke**

```bash
php artisan test --compact tests/Feature/Livewire/Schedule/MyShiftsTest.php
```

Expected: all tests PASS

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/schedule/my-shifts.blade.php
git commit -m "feat: add checkout confirmation and re-check-in button to my shifts view"
```
