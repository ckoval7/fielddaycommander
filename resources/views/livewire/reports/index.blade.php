<div>
    <x-slot:title>Reports</x-slot:title>

    @if (! $this->event)
        <p>No active event</p>
    @else
        <a href="{{ route('reports.cabrillo') }}">Cabrillo Log</a>
        <a href="{{ route('reports.club-summary') }}">Club Summary PDF</a>
    @endif
</div>
