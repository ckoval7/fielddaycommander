{{-- @var $row array{equipment_description: string, serial_number: ?string, status: string, owner_name: string, owner_callsign: ?string, station: string, value_usd: ?float, status_changed_at: ?string, circumstances: ?string} --}}
{{-- @var $alt bool --}}
<tr class="{{ $alt ? 'alt' : '' }}">
    <td>
        <strong>{{ $row['equipment_description'] }}</strong>
        @if ($row['serial_number'])
            <div style="font-size: 8px; color: #64748b;">SN: {{ $row['serial_number'] }}</div>
        @endif
    </td>
    <td class="c">{{ ucfirst(str_replace('_', ' ', $row['status'])) }}</td>
    <td>
        {{ $row['owner_name'] }}
        @if (! empty($row['owner_callsign']) && $row['owner_callsign'] !== 'N/A')
            <span class="m" style="font-size: 9px; color: #64748b;">{{ $row['owner_callsign'] }}</span>
        @endif
    </td>
    <td>{{ $row['station'] }}</td>
    <td class="r">{{ $row['value_usd'] ? '$'.number_format($row['value_usd'], 2) : '—' }}</td>
    <td class="m" style="font-size: 8px;">{{ $row['status_changed_at'] ?? '—' }}</td>
    <td>{{ $row['circumstances'] ?: '—' }}</td>
</tr>
