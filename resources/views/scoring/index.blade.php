<x-layouts.app>
    <x-slot:title>Scoring</x-slot:title>

    <div class="p-6">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumbs text-sm mb-6">
            <ul>
                <li><a href="{{ route('dashboard') }}">Home</a></li>
                <li>Scoring</li>
            </ul>
        </div>

        <!-- Page Title -->
        <h1 class="text-3xl font-bold mb-6">Scoring</h1>

        <!-- Scoring Livewire Component -->
        @livewire('scoring')
    </div>
</x-layouts.app>
