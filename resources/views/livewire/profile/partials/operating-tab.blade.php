<div class="space-y-6">
    {{-- Current Operating Session (if active) --}}
    @if(false) {{-- Placeholder - will implement when OperatingSession model exists --}}
        <div class="card bg-base-100 shadow border-2 border-error">
            <div class="card-body">
                <div class="flex items-center gap-2">
                    <div class="badge badge-error gap-1">
                        <span class="inline-block w-2 h-2 bg-error rounded-full animate-pulse"></span>
                        Currently Operating
                    </div>
                </div>

                <div class="mt-2 space-y-1">
                    <p class="text-sm">
                        <span class="font-semibold">Station:</span> Station 1 (20m Phone)
                    </p>
                    <p class="text-sm">
                        <span class="font-semibold">Event:</span> Field Day 2025
                    </p>
                    <p class="text-sm">
                        <span class="font-semibold">Started:</span> 2 hours ago
                    </p>
                    <p class="text-sm">
                        <span class="font-semibold">QSOs Logged:</span> 47
                    </p>
                </div>

                <div class="card-actions justify-end mt-4">
                    <x-button class="btn-error">End Session</x-button>
                </div>
            </div>
        </div>
    @endif

    {{-- Recent Operating Sessions --}}
    <div class="card bg-base-100 shadow">
        <div class="card-body">
            <h3 class="card-title">Recent Operating Sessions</h3>

            @if($operatingSessions->isEmpty())
                <div class="alert">
                    <x-mary-icon name="o-information-circle" class="w-5 h-5" />
                    <span>You haven't operated any stations yet.</span>
                </div>
            @else
                {{-- Table of operating sessions --}}
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Station</th>
                                <th>Band</th>
                                <th>Mode</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Duration</th>
                                <th>QSOs</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($operatingSessions as $session)
                                <tr>
                                    <td>{{ $session->event->name ?? 'N/A' }}</td>
                                    <td>{{ $session->station->name ?? 'N/A' }}</td>
                                    <td>{{ $session->band->name ?? 'N/A' }}</td>
                                    <td>{{ $session->mode->name ?? 'N/A' }}</td>
                                    <td>{{ $session->start_time?->format('M j, g:i A') }}</td>
                                    <td>{{ $session->end_time?->format('M j, g:i A') ?? 'Active' }}</td>
                                    <td>{{ $session->duration ?? 'N/A' }}</td>
                                    <td>{{ $session->qso_count ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
