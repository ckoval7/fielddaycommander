{{-- @var $row array{type: string, description: string, bands: ?string, power_watts: ?int, owner_name: string, owner_callsign: ?string, owner_contact: string, status: string} --}}
{{-- @var $alt bool --}}
<tr class="{{ $alt ? 'alt' : '' }}">
    <td>{{ ucfirst(str_replace('_', ' ', $row['type'])) }}</td>
    <td>
        <strong>{{ $row['description'] }}</strong>
        @if (! empty($row['bands']))
            <div style="font-size: 8px; color: #64748b;">Bands: {{ $row['bands'] }}</div>
        @endif
    </td>
    <td class="r">{{ $row['power_watts'] ?? '—' }}</td>
    <td>
        {{ $row['owner_name'] }}
        @if (! empty($row['owner_callsign']) && $row['owner_callsign'] !== 'N/A')
            <span class="m" style="font-size: 9px; color: #64748b;">{{ $row['owner_callsign'] }}</span>
        @endif
    </td>
    <td>{{ $row['owner_contact'] }}</td>
    <td class="c">{{ ucfirst(str_replace('_', ' ', $row['status'])) }}</td>
</tr>
