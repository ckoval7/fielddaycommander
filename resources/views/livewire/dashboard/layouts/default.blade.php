<div class="p-6" x-data="dashboardCustomizer()">
    {{-- Widget Customizer Toggle Button --}}
    <div class="mb-6 flex justify-between items-center">
        <h1 class="text-2xl font-bold">Dashboard</h1>
        <button
            @click="customizerOpen = !customizerOpen"
            class="btn btn-sm btn-outline gap-2"
            type="button"
        >
            <x-mary-icon name="phosphor-sliders-horizontal" class="w-4 h-4" />
            <span x-show="!customizerOpen">Customize Widgets</span>
            <span x-show="customizerOpen" x-cloak>Done</span>
        </button>
    </div>

    {{-- Widget Customizer Panel --}}
    <div
        x-show="customizerOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 transform -translate-y-2"
        x-transition:enter-end="opacity-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 transform -translate-y-2"
        x-cloak
        class="mb-6"
    >
        <x-mary-card title="Widget Customizer" separator>
            <x-slot:menu>
                <button
                    @click="resetToDefaults()"
                    class="btn btn-sm btn-ghost gap-1"
                    type="button"
                >
                    <x-mary-icon name="phosphor-arrow-clockwise" class="w-4 h-4" />
                    Reset to Defaults
                </button>
            </x-slot:menu>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach(config('dashboard.categories') as $categoryKey => $categoryName)
                    @php
                        $categoryWidgets = collect(config('dashboard.widgets'))
                            ->filter(fn($widget) => $widget['category'] === $categoryKey)
                            ->filter(fn($widget) => $widget['permission'] === null || auth()->user()?->can($widget['permission']));
                    @endphp

                    @if($categoryWidgets->isNotEmpty())
                        <div class="space-y-3">
                            <h3 class="font-semibold text-sm uppercase tracking-wide text-base-content/70">
                                {{ $categoryName }}
                            </h3>
                            <div class="space-y-2">
                                @foreach($categoryWidgets as $widgetKey => $widget)
                                    <label class="flex items-start gap-3 p-3 rounded-lg border border-base-300 hover:bg-base-200 cursor-pointer transition-colors">
                                        <input
                                            type="checkbox"
                                            x-model="visibleWidgets"
                                            value="{{ $widgetKey }}"
                                            class="checkbox checkbox-sm mt-0.5"
                                        >
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <x-mary-icon :name="'o-' . $widget['icon']" class="w-4 h-4 flex-shrink-0" />
                                                <span class="font-medium text-sm">{{ $widget['name'] }}</span>
                                            </div>
                                            <p class="text-xs text-base-content/60 mt-1">
                                                Size: {{ ucfirst($widget['size']) }}
                                            </p>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="mt-4 pt-4 border-t border-base-300">
                <p class="text-sm text-base-content/60">
                    <x-mary-icon name="phosphor-info" class="w-4 h-4 inline" />
                    Your preferences are saved automatically and persist across sessions.
                </p>
            </div>
        </x-mary-card>
    </div>

    {{-- Dashboard Widgets Grid --}}
    <div class="grid grid-cols-12 gap-4">
        @foreach(config('dashboard.widgets') as $widgetKey => $widget)
            @if($widget['permission'] === null || auth()->user()?->can($widget['permission']))
                <div
                    x-show="visibleWidgets.includes('{{ $widgetKey }}')"
                    :class="getWidgetGridClass('{{ $widgetKey }}')"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-cloak
                >
                    @livewire($widget['component'], ['event' => $event], key('widget-' . $widgetKey))
                </div>
            @endif
        @endforeach
    </div>

    {{-- Empty State --}}
    <div
        x-show="visibleWidgets.length === 0"
        x-transition
        x-cloak
        class="text-center py-12"
    >
        <x-mary-icon name="phosphor-squares-four" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
        <h3 class="text-xl font-semibold mb-2">No Widgets Selected</h3>
        <p class="text-base-content/70 mb-4">
            Click "Customize Widgets" above to add widgets to your dashboard.
        </p>
        <button
            @click="resetToDefaults()"
            class="btn btn-primary btn-sm"
            type="button"
        >
            Load Default Widgets
        </button>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('dashboardCustomizer', () => ({
        // Customizer panel state (persisted)
        customizerOpen: Alpine.$persist(false).as('dashboard_customizer_open'),

        // Visible widgets array (persisted)
        visibleWidgets: Alpine.$persist([]).as('dashboard_widget_prefs').using(localStorage),

        init() {
            // Load defaults if no preferences saved
            if (this.visibleWidgets.length === 0) {
                this.loadDefaults();
            }
        },

        loadDefaults() {
            const widgets = @js(config('dashboard.widgets'));
            const defaultOrder = @js(config('dashboard.default_widget_order'));

            // Filter to only default_visible widgets that user has permission for
            this.visibleWidgets = defaultOrder.filter(key => {
                const widget = widgets[key];
                if (!widget || !widget.default_visible) return false;

                // Check permission (permission: null means public)
                if (widget.permission !== null) {
                    // In Alpine, we can't check Laravel permissions, so we trust the server-side filter
                    // The Blade template already filters by permission
                    return true;
                }

                return true;
            });
        },

        resetToDefaults() {
            this.loadDefaults();
        },

        getWidgetGridClass(widgetKey) {
            const widgets = @js(config('dashboard.widgets'));
            const gridSizes = @js(config('dashboard.grid_sizes'));

            const widget = widgets[widgetKey];
            if (!widget) return 'col-span-12';

            const colSpan = gridSizes[widget.size] || 12;

            // Responsive grid classes
            return `col-span-12 lg:col-span-${colSpan}`;
        }
    }));
});
</script>
