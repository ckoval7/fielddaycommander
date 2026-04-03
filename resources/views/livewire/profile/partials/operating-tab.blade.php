<div class="space-y-6">
    {{-- Current Operating Session (if active) --}}
    @php
        $activeSession = $operatingSessions->first(fn ($s) => $s->is_active);
    @endphp

    @if($activeSession)
        <div class="card bg-base-100 shadow border-2 border-error">
            <div class="card-body">
                <div class="flex items-center gap-2">
                    <x-badge value="Currently Operating" class="badge-error gap-1">
                        <x-slot:prepend>
                            <span class="inline-block w-2 h-2 bg-error rounded-full animate-pulse"></span>
                        </x-slot:prepend>
                    </x-badge>
                </div>

                <div class="mt-2 space-y-1">
                    <p class="text-sm">
                        <span class="font-semibold">Station:</span> {{ $activeSession->station->name ?? 'N/A' }}
                        ({{ $activeSession->band->name ?? '' }} {{ $activeSession->mode->name ?? '' }})
                    </p>
                    <p class="text-sm">
                        <span class="font-semibold">Event:</span> {{ $activeSession->station->eventConfiguration->event->name ?? 'N/A' }}
                    </p>
                    <p class="text-sm">
                        <span class="font-semibold">Started:</span> {{ $activeSession->start_time->diffForHumans() }}
                    </p>
                    <p class="text-sm">
                        <span class="font-semibold">QSOs Logged:</span> {{ $activeSession->qso_count ?? 0 }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Recent Operating Sessions --}}
    <div class="card bg-base-100 shadow">
        <div class="card-body">
            <h3 class="card-title">Recent Operating Sessions</h3>

            @if($operatingSessions->isEmpty())
                <x-alert icon="o-information-circle">
                    You haven't operated any stations yet.
                </x-alert>
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
                                    <td>{{ $session->station->eventConfiguration->event->name ?? 'N/A' }}</td>
                                    <td>{{ $session->station->name ?? 'N/A' }}</td>
                                    <td>{{ $session->band->name ?? 'N/A' }}</td>
                                    <td>{{ $session->mode->name ?? 'N/A' }}</td>
                                    <td>{{ $session->start_time ? toLocalTime($session->start_time)->format('M j, g:i A T') : '' }}</td>
                                    <td>{{ $session->end_time ? toLocalTime($session->end_time)->format('M j, g:i A T') : 'Active' }}</td>
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
