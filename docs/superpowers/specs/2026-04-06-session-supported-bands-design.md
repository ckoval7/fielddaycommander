# Session Supported Bands Display Design

**Date:** 2026-04-06
**Status:** Approved

## Problem

When a user opens the session setup modal to start a logging session, they must choose a band from a full list of all Field Day bands with no guidance about which bands the station's equipment actually supports. The only feedback is a warning *after* they pick an unsupported band. Operators — especially less experienced ones — benefit from knowing upfront which bands the station is configured for.

## Goal

Show informational band context in the session setup modal, derived from the station's radio and antenna equipment, so operators can make an informed band selection before picking from the dropdown.

## Approach

Option A: informational block in the setup modal only. The band dropdown remains unchanged; a new context block above it summarises which bands the station supports. This is the right moment to surface the info — the operator is actively choosing a band — and keeps the station cards clean.

## Architecture & Data

**New computed property: `stationSupportedBands` on `StationSelect`**

Derives supported bands whenever `selectedStationId` is set:

1. Find the selected station from the already-loaded `$this->stations` collection.
2. If no `primaryRadio` → return `null` (no radio assigned).
3. Get `primaryRadio->bands` (already eager-loaded).
4. Filter `additionalEquipment` to `type === 'antenna'`. If none → return `null` (no antennas assigned).
5. Collect the union of all antenna band IDs.
6. Intersect radio band IDs with antenna union → return the matching Band models as a collection.

**Eager loading change**

The stations query in `StationSelect` currently loads `'primaryRadio.bands'`. Add `'additionalEquipment.bands'` to load antenna bands in the same query. Antenna filtering (`type === 'antenna'`) is done in PHP after loading.

**Return value semantics**

| State | Return |
|---|---|
| No radio assigned | `null` |
| No antennas assigned | `null` |
| Radio + antennas configured, overlap exists | `Collection` of Band models |
| Radio + antennas configured, no overlap | Empty `Collection` |

`null` means "cannot determine" (missing data). Empty collection means "determined, but no overlap."

## UI Display

The new block appears **above the band dropdown** in the setup modal.

| Condition | Display |
|---|---|
| `null` (no radio or no antennas) | `alert-info`: "No radio/antennas assigned — band compatibility unknown." (two separate messages depending on which is missing) |
| Empty collection (no overlap) | `alert-warning`: "No bands are supported by both the radio and antennas at this station." |
| Non-empty collection | Label "Station supports:" + `badge-outline badge-sm` badges for each band name |

The existing `bandWarning` block (which fires after band selection when the chosen band isn't supported by the radio) remains in place beneath the dropdown, unchanged.

## Testing

A feature test for `StationSelect` covering:

1. Station with radio + antenna with overlapping bands → computed returns only the intersecting bands.
2. Station with radio that has no bands configured → computed returns `null`.
3. Station with no antennas assigned → computed returns `null`.
4. Station with radio + antenna that share no bands → computed returns an empty collection.
