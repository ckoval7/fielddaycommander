{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    @if(!$eventConfigurationId)
        <x-card class="shadow-md">
            <div class="text-center py-12">
                <x-icon name="phosphor-warning-circle" class="w-16 h-16 mx-auto text-warning" />
                <p class="mt-4 text-lg font-medium">No Logbook Available</p>
                <p class="text-sm text-base-content/70 mt-2">Please configure an event before viewing its logbook.</p>
            </div>
        </x-card>
    @else
        {{-- Stats Summary --}}
        <div wire:loading.class="opacity-50 transition-opacity duration-200" class="mb-6">
            @include('livewire.logbook.partials.stats-summary')
        </div>

        {{-- Filter Panel --}}
        <div class="mb-6">
            @include('livewire.logbook.partials.filter-panel')
        </div>

        {{-- Results View --}}
        <div>
            @include('livewire.logbook.partials.results-view')
        </div>

        {{-- Contact Editor (child component) --}}
        @can('edit-contacts')
            <livewire:logbook.contact-editor />
        @endcan
    @endif
</div>
