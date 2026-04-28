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
            @include('equipment.reports.partials.paginated-table', [
                'rows' => $incidents,
                'columns' => [
                    ['label' => 'Equipment'],
                    ['label' => 'Status', 'attrs' => 'style="width: 11%;"'],
                    ['label' => 'Owner'],
                    ['label' => 'Station', 'attrs' => 'style="width: 13%;"'],
                    ['label' => 'Value', 'attrs' => 'class="r" style="width: 10%;"'],
                    ['label' => 'Changed', 'attrs' => 'style="width: 13%;"'],
                    ['label' => 'Circumstances'],
                ],
                'rowPartial' => 'equipment.reports.partials.incident-row',
                'rowHeight' => fn ($r) => ! empty($r['serial_number']) || (! empty($r['owner_callsign']) && $r['owner_callsign'] !== 'N/A') ? 30 : 20,
                // Page 1 also carries the Summary section (~70pt) above this table.
                'firstChunkBudget' => 465,
            ])
        @endif
    </div>
@endsection
