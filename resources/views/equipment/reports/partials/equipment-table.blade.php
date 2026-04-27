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
    'rowHeight' => fn ($r) => ! empty($r['bands']) || (! empty($r['owner_callsign']) && $r['owner_callsign'] !== 'N/A') ? 30 : 20,
    // Station inventory stacks multiple per-station tables on the same page; assume
    // each station only owns a small slice of the first page it appears on.
    'firstChunkBudget' => 400,
])
