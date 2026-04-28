{{--
    Return checklist row. Dispatches between two row types via $row['_type']:
    - 'group': owner sub-header that spans all columns
    - 'item':  the actual checklist line (default if _type is missing)
--}}
{{-- @var $row array<string, mixed> --}}
{{-- @var $alt bool --}}
@if (($row['_type'] ?? 'item') === 'group')
    <tr>
        <td colspan="5" style="background: #e8eef9; font-weight: bold; font-size: 10px; padding: 5px 8px; border: 1px solid #cbd5e1;">
            {{ $row['owner_name'] }}
            @if (! empty($row['owner_callsign']) && $row['owner_callsign'] !== 'N/A')
                <span class="m" style="font-size: 9px; color: #1e3ea8;">{{ $row['owner_callsign'] }}</span>
            @endif
            <span style="font-weight: normal; color: #94a3b8; font-style: italic; font-size: 9px;">&nbsp;&middot;&nbsp; {{ $row['count'] }} {{ Str::plural('item', $row['count']) }}</span>
        </td>
    </tr>
@else
    <tr class="{{ $alt ? 'alt' : '' }}">
        <td class="c"><span class="check-box">&#9744;</span></td>
        <td>
            <strong>{{ $row['equipment_description'] }}</strong>
            <div style="font-size: 8px; color: #64748b;">{{ ucfirst(str_replace('_', ' ', $row['type'])) }}</div>
        </td>
        <td class="m">{{ $row['serial_number'] ?: '—' }}</td>
        <td>{{ $row['station'] ?? 'Unassigned' }}</td>
        <td class="c"><span class="signature-line">&nbsp;</span></td>
    </tr>
@endif
