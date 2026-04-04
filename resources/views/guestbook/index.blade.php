@php
    $activeEvent = \App\Models\Event::active()->with('eventConfiguration')->first()
        ?? \App\Models\Event::inSetupWindow()->orderBy('start_time')->with('eventConfiguration')->first();
@endphp

<x-layouts.app>
    <x-slot:title>Sign Our Guestbook</x-slot:title>

    <div class="p-4 sm:p-6">
        {{-- Page Title and Welcome Message --}}
        <div class="mb-8">
            <h1 class="text-3xl sm:text-4xl font-bold mb-3">Sign Our Guestbook</h1>
            <p class="text-base sm:text-lg text-base-content/70 max-w-2xl">
                Thank you for visiting our Field Day event! We'd love to hear from you. Please take a moment
                to sign our guestbook and share your experience with us.
            </p>
        </div>

        {{-- No Active Event Message --}}
        @if (! $activeEvent || ! $activeEvent->eventConfiguration || ! $activeEvent->eventConfiguration->guestbook_enabled)
            <div class="alert alert-info mb-8">
                <x-icon name="o-information-circle" class="w-6 h-6" />
                <div>
                    <h3 class="font-bold">No Active Event</h3>
                    <p>Currently, there is no active Field Day event accepting guestbook entries. Please check back later!</p>
                </div>
            </div>
        @else
            {{-- Two-Column Layout for Form and Entries --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                {{-- Left Column: Guestbook Form --}}
                <div>
                    <div class="card bg-base-100 shadow-md p-4 sm:p-6">
                        <h2 class="card-title text-xl sm:text-2xl mb-4">Add Your Entry</h2>
                        <livewire:guestbook.guestbook-form />
                    </div>
                </div>

                {{-- Right Column: Guestbook Entries List --}}
                <div>
                    <div class="card bg-base-100 shadow-md p-4 sm:p-6">
                        <h2 class="card-title text-xl sm:text-2xl mb-4">Recent Entries</h2>
                        <livewire:guestbook.guestbook-list />
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
