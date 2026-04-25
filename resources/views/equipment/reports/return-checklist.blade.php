@extends('equipment.reports.layout', ['report_title' => 'Equipment Return Checklist'])

@section('content')
    <div class="section">
        <div class="section-title">
            Return Checklist
            <span class="section-note">{{ $summary['total_items'] }} {{ Str::plural('item', $summary['total_items']) }} to return</span>
        </div>

        @if ($return_items->isEmpty())
            <p class="muted">No equipment is currently delivered and pending return.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th class="c" style="width: 24px;">&#9744;</th>
                        <th>Equipment</th>
                        <th style="width: 14%;">Serial #</th>
                        <th>Owner</th>
                        <th style="width: 16%;">Station</th>
                        <th class="c" style="width: 22%;">Signature</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($return_items as $i => $item)
                        <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                            <td class="c"><span class="check-box">&#9744;</span></td>
                            <td>
                                <strong>{{ $item['equipment_description'] }}</strong>
                                <div style="font-size: 8px; color: #64748b;">{{ ucfirst(str_replace('_', ' ', $item['type'])) }}</div>
                            </td>
                            <td class="m">{{ $item['serial_number'] ?: '—' }}</td>
                            <td>
                                {{ $item['owner_name'] }}
                                @if (! empty($item['owner_callsign']) && $item['owner_callsign'] !== 'N/A')
                                    <span class="m" style="font-size: 9px; color: #64748b;">{{ $item['owner_callsign'] }}</span>
                                @endif
                            </td>
                            <td>{{ $item['station'] ?? 'Unassigned' }}</td>
                            <td class="c"><span class="signature-line">&nbsp;</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
