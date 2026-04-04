# Checkout Confirmation & Re-Check-In Design

**Date:** 2026-04-04

## Problem

Checkout is currently permanent and instant — no confirmation, no way to undo. A user who accidentally checks out mid-shift has no recourse.

## Solution

Two behaviours:

1. **Confirmation before early checkout** — when a user clicks Check Out while the shift is still active, show a dynamic `wire:confirm` dialog showing time remaining (e.g. "You still have 45 minutes left in this shift. Are you sure you want to check out?"). No dialog when the shift has already ended.

2. **Re-check-in** — a "Check In Again" button appears on current shifts where the assignment status is `checked_out`. Only available while the shift is still active. Reverts status to `checked_in`, clears `checked_out_at`, and preserves the original `checked_in_at`.

## Changes

### `ShiftAssignment` model

Add `checkBackIn()`: sets `status` → `checked_in`, nulls `checked_out_at`, leaves `checked_in_at` unchanged.

### `MyShifts` Livewire component

Add `reCheckIn(int $assignmentId)`: validates ownership and `checked_out` status, confirms shift is still active via `is_current`, calls `checkBackIn()`, clears computed caches, dispatches toast.

### `my-shifts.blade.php`

- Check Out button: add dynamic `wire:confirm` message with minutes remaining when `appNow()->isBefore($shift->end_time)`.
- Current Shifts section: add "Check In Again" button for `checked_out` assignments (only shown when shift `is_current`).

## Constraints

- Re-check-in is only available while the shift's `end_time` is in the future.
- `checked_in_at` is always preserved on re-check-in.
- No confirmation required when checking out after the shift ends.
