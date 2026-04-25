{{-- @var $equipment iterable<array<string, mixed>> --}}
<table>
    <thead>
        <tr>
            <th style="width: 14%;">Type</th>
            <th>Equipment</th>
            <th class="r" style="width: 9%;">Power (W)</th>
            <th>Owner</th>
            <th>Contact</th>
            <th class="c" style="width: 11%;">Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($equipment as $i => $eq)
            <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                <td>{{ ucfirst(str_replace('_', ' ', $eq['type'])) }}</td>
                <td>
                    <strong>{{ $eq['description'] }}</strong>
                    @if (! empty($eq['bands']))
                        <div style="font-size: 8px; color: #64748b;">Bands: {{ $eq['bands'] }}</div>
                    @endif
                </td>
                <td class="r">{{ $eq['power_watts'] ?? '—' }}</td>
                <td>
                    {{ $eq['owner_name'] }}
                    @if (! empty($eq['owner_callsign']) && $eq['owner_callsign'] !== 'N/A')
                        <span class="m" style="font-size: 9px; color: #64748b;">{{ $eq['owner_callsign'] }}</span>
                    @endif
                </td>
                <td>{{ $eq['owner_contact'] }}</td>
                <td class="c">{{ ucfirst(str_replace('_', ' ', $eq['status'])) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
