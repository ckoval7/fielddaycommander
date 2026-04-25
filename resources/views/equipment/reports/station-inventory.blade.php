@extends('equipment.reports.layout', ['report_title' => 'Station Equipment Inventory'])

@section('content')
    @forelse ($stations as $station)
        <div class="section">
            <div class="section-title">
                {{ $station['station_name'] }}
                <span class="section-note">{{ $station['equipment_count'] }} {{ Str::plural('item', $station['equipment_count']) }}</span>
            </div>
            @include('equipment.reports.partials.equipment-table', ['equipment' => $station['equipment']])
        </div>
    @empty
        <div class="section">
            <div class="section-title">Stations</div>
            <p class="muted">No equipment has been assigned to a station yet.</p>
        </div>
    @endforelse

    @if ($unassigned_equipment->isNotEmpty())
        <div class="section">
            <div class="section-title">
                Unassigned Equipment
                <span class="section-note">{{ $unassigned_equipment->count() }} {{ Str::plural('item', $unassigned_equipment->count()) }}</span>
            </div>
            @include('equipment.reports.partials.equipment-table', ['equipment' => $unassigned_equipment])
        </div>
    @endif
@endsection
