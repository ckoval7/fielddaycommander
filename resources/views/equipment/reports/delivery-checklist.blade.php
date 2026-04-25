@extends('equipment.reports.layout', ['report_title' => 'Equipment Delivery Checklist'])

@section('content')
    <div class="section">
        <div class="section-title">
            Delivery Checklist
            <span class="section-note">{{ $checklist_items->count() }} items expected</span>
        </div>

        @if ($checklist_items->isEmpty())
            <p class="muted">No equipment scheduled for delivery.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th class="c" style="width: 24px;">&#9744;</th>
                        <th style="width: 18%;">Expected</th>
                        <th>Equipment</th>
                        <th>Owner</th>
                        <th>Contact</th>
                        <th class="c" style="width: 22%;">Signature</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($checklist_items as $i => $item)
                        <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                            <td class="c"><span class="check-box">&#9744;</span></td>
                            <td class="m">{{ $item['expected_delivery'] }}</td>
                            <td>
                                <strong>{{ $item['equipment_description'] }}</strong>
                                <div style="font-size: 8px; color: #64748b;">{{ ucfirst(str_replace('_', ' ', $item['type'])) }}</div>
                            </td>
                            <td>
                                {{ $item['owner_name'] }}
                                @if ($item['owner_callsign'] && $item['owner_callsign'] !== 'N/A')
                                    <span class="m" style="font-size: 9px; color: #64748b;">{{ $item['owner_callsign'] }}</span>
                                @endif
                            </td>
                            <td>{{ $item['owner_phone'] }}</td>
                            <td class="c"><span class="signature-line">&nbsp;</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
