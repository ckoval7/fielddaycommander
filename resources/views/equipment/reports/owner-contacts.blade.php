@extends('equipment.reports.layout', ['report_title' => 'Equipment Owner Contact List'])

@section('content')
    <div class="section">
        <div class="section-title">
            Owner Contacts
            <span class="section-note">{{ $contacts->count() }} {{ Str::plural('owner', $contacts->count()) }}</span>
        </div>

        @if ($contacts->isEmpty())
            <p class="muted">No equipment owners on record for this event.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Owner</th>
                        <th style="width: 12%;">Callsign</th>
                        <th>Email</th>
                        <th>Emergency Contacts</th>
                        <th class="r" style="width: 11%;">Equipment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($contacts as $i => $contact)
                        <tr class="{{ $i % 2 === 1 ? 'alt' : '' }}">
                            <td>{{ $contact['owner_name'] }}</td>
                            <td class="m">{{ $contact['callsign'] }}</td>
                            <td>{{ $contact['email'] }}</td>
                            <td>
                                @if ($contact['emergency_contacts']->isNotEmpty())
                                    {{ $contact['emergency_contacts']->implode(', ') }}
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="r">{{ $contact['equipment_count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
