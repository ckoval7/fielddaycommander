{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- @var $equipment iterable<array<string, mixed>> --}}
{{--
    Renders a chunked equipment table for one station (or "Unassigned" group).
    Used by station-inventory.blade.php.
--}}
@include('equipment.reports.partials.paginated-table', [
    'rows' => $equipment,
    'columns' => [
        ['label' => 'Type', 'attrs' => 'style="width: 14%;"'],
        ['label' => 'Equipment'],
        ['label' => 'Power (W)', 'attrs' => 'class="r" style="width: 9%;"'],
        ['label' => 'Owner'],
        ['label' => 'Contact'],
        ['label' => 'Status', 'attrs' => 'class="c" style="width: 11%;"'],
    ],
    'rowPartial' => 'equipment.reports.partials.equipment-row',
    // A bands line usually wraps to two lines in the narrow Equipment column.
    'rowHeight' => fn ($r) => match (true) {
        ! empty($r['bands']) => 40,
        ! empty($r['owner_callsign']) && $r['owner_callsign'] !== 'N/A' => 30,
        default => 20,
    },
    // Station inventory stacks multiple per-station tables on the same page; assume
    // each station only owns a small slice of the first page it appears on.
    'firstChunkBudget' => 400,
])
