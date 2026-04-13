# Map Settings Persistence Design

**Date:** 2026-04-13

## Problem

The section map resets `view` (geographic region) and `colorMode` (coloring scheme) to defaults on every page load. Users lose their preferred settings when navigating away and back.

## Solution

Persist `view` and `colorMode` in `sessionStorage` (tab-scoped, clears on tab close). Follows the same pattern as the dashboard layout selector.

## Implementation

**File:** `resources/views/livewire/section-map.blade.php`

In the Alpine `x-data` block:

- **`init()`**: read `section_map_view` and `section_map_color_mode` from `sessionStorage`; apply to `view` and `colorMode` if present.
- **`$watch('view', ...)`**: write new value to `sessionStorage` on change.
- **`$watch('colorMode', ...)`**: write new value to `sessionStorage` on change.

No server-side changes. No new files.

## Storage Keys

| Key | Values |
|-----|--------|
| `section_map_view` | `all`, `us`, `ca`, `w0`–`w9` |
| `section_map_color_mode` | `band`, `qso`, `time` |

## Testing

Update `SectionMapTest` to verify the component renders with the expected defaults (server-side only; sessionStorage behavior is client-side and not covered by Pest feature tests).
