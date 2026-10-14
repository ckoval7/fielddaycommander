<x-layouts.app>
    <x-slot:title>Logbook Browser</x-slot:title>

    <div class="p-6 max-sm:px-0">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumbs text-sm mb-6">
            <ul>
                <li><a href="{{ route('dashboard') }}">Home</a></li>
                <li>Logbook</li>
            </ul>
        </div>

        <!-- Page Title -->
        <h1 class="text-3xl font-bold mb-6">Logbook Browser</h1>

        <!-- LogbookBrowser Livewire Component -->
        @livewire('logbook.logbook-browser')
    </div>
</x-layouts.app>
