<x-layouts.app>
    <x-slot:title>Dashboard</x-slot:title>
    <div class="p-6">
        <div class="flex flex-col items-center justify-center min-h-[50vh] text-center">
            <x-mary-icon name="phosphor-calendar" class="w-16 h-16 text-base-content/30 mb-4" />
            <h1 class="text-2xl font-bold mb-2">No Active Event</h1>
            <p class="text-base-content/60 mb-6 max-w-md">
                There is no Field Day event currently in progress. The dashboard will become available when an event starts.
            </p>

            @if($upcomingEvents->isNotEmpty())
                <div class="w-full max-w-md">
                    <h2 class="text-lg font-semibold mb-3">Upcoming Events</h2>
                    <div class="space-y-2">
                        @foreach($upcomingEvents as $event)
                            <div class="card bg-base-100 shadow">
                                <div class="card-body p-4">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium">{{ $event->name }}</span>
                                        <span class="text-sm text-base-content/60">
                                            {{ $event->start_time->format('M j, Y') }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @can('create-events')
                <a href="{{ route('events.create') }}" class="btn btn-primary mt-6">
                    <x-mary-icon name="phosphor-plus" class="w-4 h-4" />
                    Create Event
                </a>
            @endcan
        </div>
    </div>
</x-layouts.app>
