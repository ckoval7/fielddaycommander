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
            @include('equipment.reports.partials.paginated-table', [
                'rows' => $contacts,
                'columns' => [
                    ['label' => 'Owner'],
                    ['label' => 'Callsign', 'attrs' => 'style="width: 12%;"'],
                    ['label' => 'Email'],
                    ['label' => 'Emergency Contacts'],
                    ['label' => 'Equipment', 'attrs' => 'class="r" style="width: 11%;"'],
                ],
                'rowPartial' => 'equipment.reports.partials.owner-contact-row',
                'rowHeight' => fn ($r) => 20,
            ])
        @endif
    </div>
@endsection
