@extends('equipment.reports.layout', ['report_title' => 'Equipment Incident Report'])

@section('content')
    <div class="section">
        <div class="section-title">Summary</div>
        <table class="summary-grid">
            <tr>
                <td style="width: 50%;">
                    <div class="summary-label">Total Incidents</div>
                    <div class="summary-value">{{ $summary['total_incidents'] }}</div>
                </td>
                <td>
                    <div class="summary-label">Total Value at Risk (USD)</div>
                    <div class="summary-value">${{ $summary['total_value_at_risk'] }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="section-title">
            Incident Details
            <span class="section-note">lost, damaged, or cancelled equipment</span>
        </div>

        @if ($incidents->isEmpty())
            <p class="muted">No incidents recorded for this event.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Equipment</th>
                        <th style="width: 11%;">Status</th>
                        <th>Owner</th>
                        <th style="width: 13%;">Station</th>
                        <th class="r" style="width: 10%;">Value</th>
                        <th style="width: 13%;">Changed</th>
                        <th>Circumstances</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($incidents as $i => $incident)
                        <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                            <td>
                                <strong>{{ $incident['equipment_description'] }}</strong>
                                @if ($incident['serial_number'])
                                    <div style="font-size: 8px; color: #64748b;">SN: {{ $incident['serial_number'] }}</div>
                                @endif
                            </td>
                            <td class="c">{{ ucfirst(str_replace('_', ' ', $incident['status'])) }}</td>
                            <td>
                                {{ $incident['owner_name'] }}
                                @if (! empty($incident['owner_callsign']) && $incident['owner_callsign'] !== 'N/A')
                                    <span class="m" style="font-size: 9px; color: #64748b;">{{ $incident['owner_callsign'] }}</span>
                                @endif
                            </td>
                            <td>{{ $incident['station'] }}</td>
                            <td class="r">{{ $incident['value_usd'] ? '$'.number_format($incident['value_usd'], 2) : '—' }}</td>
                            <td class="m" style="font-size: 8px;">{{ $incident['status_changed_at'] ?? '—' }}</td>
                            <td>{{ $incident['circumstances'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
