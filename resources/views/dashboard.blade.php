<x-layouts.app>
    <x-slot:title>Dashboard</x-slot:title>
    <div class="p-6">
        <h1 class="text-3xl font-bold mb-6">Field Day Dashboard</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {{-- Welcome Card --}}
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title">Welcome!</h2>
                    <p>You're logged in to the Field Day Logging Database.</p>
                </div>
            </div>

            {{-- Quick Stats Placeholder --}}
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title">Quick Stats</h2>
                    <p class="text-sm text-gray-500">Event statistics will appear here once an event is active.</p>
                </div>
            </div>

            {{-- Recent Activity Placeholder --}}
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title">Recent Activity</h2>
                    <p class="text-sm text-gray-500">Recent contacts will appear here.</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
