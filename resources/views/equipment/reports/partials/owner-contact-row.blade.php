{{-- @var $row array{owner_name: string, callsign: string, email: string, emergency_contacts: \Illuminate\Support\Collection, equipment_count: int} --}}
{{-- @var $alt bool --}}
<tr class="{{ $alt ? 'alt' : '' }}">
    <td>{{ $row['owner_name'] }}</td>
    <td class="m">{{ $row['callsign'] }}</td>
    <td>{{ $row['email'] }}</td>
    <td>
        @if ($row['emergency_contacts']->isNotEmpty())
            {{ $row['emergency_contacts']->implode(', ') }}
        @else
            <span class="muted">—</span>
        @endif
    </td>
    <td class="r">{{ $row['equipment_count'] }}</td>
</tr>
