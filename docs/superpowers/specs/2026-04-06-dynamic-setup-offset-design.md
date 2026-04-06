# Dynamic Setup Offset Design

**Date:** 2026-04-06
**Status:** Approved

## Problem

`setup_allowed_from` on `Event` is currently calculated using a hardcoded ARRL Field Day Rule 3.3 formula (0000Z on the preceding Friday), applied exclusively to FD events. This makes practice/drill events impossible to configure with a meaningful setup window, since they may start on any day of the week at any time.

## Goal

Make setup window calculation data-driven per event type so that any type can opt in to a setup window with an arbitrary hour offset, without code changes.

## Scope

FD and WFD only. No UI changes — `setup_allowed_from` remains auto-calculated, not user-editable.

---

## Design

### Schema

Add `setup_offset_hours` (nullable unsigned integer) to the `event_types` table.

- `null` — no setup window (current WFD behavior)
- integer — setup opens this many hours before `start_time`

Seed values:
- **FD:** `24`
- **WFD:** `null`

### Calculation

`Event::calculateSetupAllowedFrom(Carbon $startTime, int $offsetHours): Carbon`

Replace the "preceding Friday at 0000Z" logic with:

```php
return $startTime->copy()->subHours($offsetHours);
```

Remove the ARRL Rule 3.3 PHPDoc comment — it no longer applies.

### EventForm

Replace the hardcoded `$eventType?->code === 'FD'` guard with `$eventType?->setup_offset_hours !== null`.

Pass `$eventType->setup_offset_hours` to `calculateSetupAllowedFrom()` when populating `setup_allowed_from` on create, update, and clone paths.

The `setupAllowedFrom` computed property and its form display badge update automatically.

### EventType Model

Add `setup_offset_hours` to `$fillable`. No cast needed (integer column, nullable).

---

## Testing

- Update `Event::calculateSetupAllowedFrom()` unit tests to use the new signature and assert subtraction logic.
- Update EventForm feature tests that assert the FD setup window display value.
- Add a test asserting WFD events produce `setup_allowed_from = null`.
- Existing `EventContextSelector` tests covering the `setup` status bucket remain valid.
