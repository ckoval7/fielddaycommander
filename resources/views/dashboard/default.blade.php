{{--
Main Dashboard Layout

Responsive dashboard with customizable widgets, edit mode, and dashboard management.
Supports 1/2/3/4 column responsive grid layout.

Props from controller:
- $dashboard: Dashboard model instance
- $widgets: Collection of widget configurations
--}}

<x-layouts.app>
    {{-- Connection Monitor --}}
    <x-dashboard.connection-monitor />

    <div class="container mx-auto px-4 sm:px-6 py-4 sm:py-6" x-data="{
        editMode: false,

        init() {
            // Listen for edit mode changes from DashboardEditor component
            window.addEventListener('edit-mode-changed', (e) => {
                this.editMode = e.detail.enabled;
            });

            // Reload page when dashboard is saved to get updated title/description
            window.addEventListener('dashboard-saved', () => {
                window.location.reload();
            });
        }
    }">

        {{-- Dashboard Header (hidden in edit mode) --}}
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
                        wire:model.live="selectedDashboardId"
                        :options="$userDashboards"
                        option-value="id"
                        option-label="title"
                        placeholder="Switch Dashboard"
                        icon="o-rectangle-stack"
                        class="select-sm w-full sm:w-auto"
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

        {{-- Dashboard Editor Component (handles edit mode) --}}
        <livewire:dashboard.dashboard-editor :dashboard="$dashboard" />

        {{-- Widget Grid (Normal Display Mode) --}}
        @if($widgets && $widgets->isNotEmpty())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 min-[1800px]:grid-cols-4 grid-flow-row-dense gap-4 sm:gap-6 widget-grid">
                @foreach($widgets as $widget)
                    @if($widget['visible'] ?? true)
                        @php
                            $colSpan = $widget['col_span'] ?? 1;
                            $rowSpan = $widget['row_span'] ?? 1;
                            $spanClasses = match($colSpan) {
                                2 => 'sm:col-span-2',
                                3 => 'sm:col-span-3',
                                4 => 'sm:col-span-4',
                                default => '',
                            };
                            $rowSpanClasses = match($rowSpan) {
                                2 => 'row-span-2',
                                3 => 'row-span-3',
                                default => '',
                            };
                        @endphp
                        <div
                            class="widget-item animate-fade-in {{ $spanClasses }} {{ $rowSpanClasses }}"
                            wire:key="widget-{{ $widget['id'] }}"
                            x-data="{ updating: false }"
                            @widget-updating.window="if ($event.detail.id === '{{ $widget['id'] }}') updating = true"
                            @widget-updated.window="if ($event.detail.id === '{{ $widget['id'] }}') { updating = false; $el.classList.add('widget-pulse'); setTimeout(() => $el.classList.remove('widget-pulse'), 600); }"
                        >
                            {{-- Widget Card with Update Indicator --}}
                            <div class="relative h-full">
                                {{-- Loading indicator overlay --}}
                                <div
                                    x-show="updating"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0"
                                    class="absolute inset-0 bg-base-100/50 backdrop-blur-[2px] rounded-lg flex items-center justify-center z-10"
                                    style="display: none;"
                                >
                                    <span class="loading loading-spinner loading-md text-primary"></span>
                                </div>

                                {{-- Render Widget Component --}}
                                @switch($widget['type'])
                                    @case('stat_card')
                                        <livewire:dashboard.widgets.stat-card
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="normal"
                                            wire:key="stat-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('chart')
                                        <livewire:dashboard.widgets.chart
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="normal"
                                            wire:key="chart-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('progress_bar')
                                        <livewire:dashboard.widgets.progress-bar
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="normal"
                                            wire:key="progress-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('list_widget')
                                        <livewire:dashboard.widgets.list-widget
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="normal"
                                            wire:key="list-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('timer')
                                        <livewire:dashboard.widgets.timer
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="normal"
                                            wire:key="timer-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('info_card')
                                        <livewire:dashboard.widgets.info-card
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="normal"
                                            wire:key="info-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('feed')
                                        <livewire:dashboard.widgets.feed
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="normal"
                                            wire:key="feed-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('sections_worked')
                                        <livewire:dashboard.widgets.sections-worked
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="normal"
                                            wire:key="sections-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @default
                                        <x-card class="h-full">
                                            <div class="flex flex-col items-center justify-center py-8 text-base-content/50">
                                                <x-icon name="o-question-mark-circle" class="w-12 h-12 mb-3" />
                                                <p class="text-sm">Unknown widget type: {{ $widget['type'] }}</p>
                                            </div>
                                        </x-card>
                                @endswitch
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @else
            {{-- Empty State --}}
            <div class="flex flex-col items-center justify-center py-16 text-center">
                <x-icon name="o-squares-2x2" class="w-20 h-20 text-base-content/30 mb-4" />
                <h2 class="text-xl font-bold text-base-content mb-2">No widgets configured</h2>
                <p class="text-base-content/60 mb-6">Add widgets to customize your dashboard</p>
                <x-button
                    label="Add Your First Widget"
                    icon="o-plus"
                    class="btn-primary"
                    @click="Livewire.dispatch('toggle-edit-mode')"
                />
            </div>
        @endif

        {{-- Dashboard Manager Modal --}}
        <livewire:dashboard.dashboard-manager />
    </div>

    @push('styles')
    <style>
        /* Widget animations */
        .widget-item {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .widget-item:hover {
            transform: translateY(-2px);
        }

        .widget-pulse {
            animation: widget-update-pulse 0.6s ease-out;
        }

        @keyframes widget-update-pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(var(--p), 0);
            }
            50% {
                box-shadow: 0 0 0 8px rgba(var(--p), 0.3);
            }
        }

        .animate-fade-in {
            animation: fade-in 0.3s ease-out;
        }

        @keyframes fade-in {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Stagger animation for initial grid load */
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
