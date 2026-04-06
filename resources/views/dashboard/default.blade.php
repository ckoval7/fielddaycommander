{{--
Main Dashboard Layout

Header + DashboardEditor component (which renders the live widget grid).
Edit mode is handled entirely within the editor component.

Props from controller:
- $dashboard: Dashboard model instance
- $widgets: Collection of widget configurations (used only for empty check)
--}}

<x-layouts.app>
    {{-- Connection Monitor --}}
    <x-dashboard.connection-monitor />

    <div class="container mx-auto px-4 sm:px-6 py-4 sm:py-6" x-data="{
        editMode: false,

        init() {
            window.addEventListener('edit-mode-changed', (e) => {
                this.editMode = e.detail.enabled;
            });

            window.addEventListener('dashboard-saved', () => {
                window.location.reload();
            });
        }
    }">

        {{-- Dashboard Header (hidden in edit mode — title/desc editing moves to toolbar) --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between mb-4 sm:mb-6" x-show="!editMode" x-transition>
            <div class="min-w-0">
                <h1 class="text-2xl sm:text-3xl font-bold text-base-content truncate">
                    {{ $dashboard->title }}
                </h1>
                @if($dashboard->description)
                    <p class="text-sm sm:text-base text-base-content/70 mt-1">
                        {{ $dashboard->description }}
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap gap-2 items-center flex-shrink-0">
                {{-- Dashboard Switcher --}}
                @php
                    $userDashboards = auth()->user()->dashboards;
                @endphp
                @if($userDashboards->count() > 1)
                    <x-select
                        :value="$dashboard->id"
                        :options="$userDashboards"
                        option-value="id"
                        option-label="title"
                        placeholder="Switch Dashboard"
                        icon="o-rectangle-stack"
                        class="select-sm w-full sm:w-auto"
                        x-on:change="window.location.href = '/?dashboard=' + $event.target.value"
                    />
                @endif

                {{-- Edit Layout Button --}}
                <x-button
                    label="Edit Layout"
                    icon="o-pencil-square"
                    class="btn-outline btn-sm min-h-[2.75rem] sm:min-h-[1.75rem]"
                    @click="Livewire.dispatch('toggle-edit-mode')"
                />

                {{-- Dashboard Management Button --}}
                <x-button
                    label="Manage"
                    icon="o-cog-6-tooth"
                    class="btn-ghost btn-sm min-h-[2.75rem] sm:min-h-[1.75rem]"
                    @click="Livewire.dispatch('open-modal', { modalId: 'dashboard-manager' })"
                />
            </div>
        </div>

        {{-- Dashboard Editor — renders toolbar + live widget grid --}}
        <livewire:dashboard.dashboard-editor :dashboard="$dashboard" />

        {{-- Dashboard Manager Modal --}}
        <livewire:dashboard.dashboard-manager />
    </div>

    @push('styles')
    <style>
        .widget-item {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .widget-pulse {
            animation: widget-update-pulse 0.6s ease-out;
        }

        @keyframes widget-update-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(var(--p), 0); }
            50% { box-shadow: 0 0 0 8px rgba(var(--p), 0.3); }
        }

        .animate-fade-in {
            animation: fade-in 0.3s ease-out;
        }

        @keyframes fade-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .widget-grid > .widget-item {
            animation-delay: calc(var(--stagger-delay, 0) * 50ms);
        }
        .widget-grid > .widget-item:nth-child(1) { --stagger-delay: 0; }
        .widget-grid > .widget-item:nth-child(2) { --stagger-delay: 1; }
        .widget-grid > .widget-item:nth-child(3) { --stagger-delay: 2; }
        .widget-grid > .widget-item:nth-child(4) { --stagger-delay: 3; }
        .widget-grid > .widget-item:nth-child(5) { --stagger-delay: 4; }
        .widget-grid > .widget-item:nth-child(6) { --stagger-delay: 5; }
        .widget-grid > .widget-item:nth-child(7) { --stagger-delay: 6; }
        .widget-grid > .widget-item:nth-child(8) { --stagger-delay: 7; }
        .widget-grid > .widget-item:nth-child(n+9) { --stagger-delay: 8; }
    </style>
    @endpush
</x-layouts.app>
