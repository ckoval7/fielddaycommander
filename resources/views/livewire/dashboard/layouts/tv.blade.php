<div
    class="min-h-screen bg-base-100"
    data-theme="tvdashboard"
    x-data="tvDashboard()"
    @keydown.f.window.prevent="toggleHeader()"
>
    {{-- Header (toggleable with F key) --}}
    <div
        x-show="headerVisible"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 transform -translate-y-4"
        x-transition:enter-end="opacity-100 transform translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 transform translate-y-0"
        x-transition:leave-end="opacity-0 transform -translate-y-4"
        class="bg-neutral border-b border-neutral-content/10 py-6"
    >
        <div class="container mx-auto px-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-6">
                    <h1 class="text-5xl font-bold">Field Day Dashboard</h1>
                    @if($event)
                        <span class="badge badge-success badge-lg text-2xl px-6 py-4">
                            <x-mary-icon name="phosphor-play-circle" class="w-6 h-6 mr-2" />
                            LIVE
                        </span>
                    @endif
                </div>
                <div class="text-3xl text-base-content/50">
                    Press F to toggle header
                </div>
            </div>
        </div>
    </div>

    {{-- TV Dashboard Grid --}}
    <div class="container mx-auto px-8 py-8">
        <div class="grid grid-cols-12 gap-6">
            {{-- Hero Metrics Row: QSO Count (6 cols) + Score (6 cols) --}}
            @foreach(config('dashboard.tv_default_widgets') as $widgetKey)
                @php
                    $widget = config("dashboard.widgets.{$widgetKey}");
                @endphp

                @if($widget && $widget['tv_visible'] && ($widget['permission'] === null || auth()->user()?->can($widget['permission'])))
                    @if($widgetKey === 'qso-count')
                        <div class="col-span-12 lg:col-span-6">
                            @livewire($widget['component'], ['event' => $event, 'tvMode' => true], key('tv-widget-' . $widgetKey))
                        </div>
                    @endif
                @endif
            @endforeach

            @foreach(config('dashboard.tv_default_widgets') as $widgetKey)
                @php
                    $widget = config("dashboard.widgets.{$widgetKey}");
                @endphp

                @if($widget && $widget['tv_visible'] && ($widget['permission'] === null || auth()->user()?->can($widget['permission'])))
                    @if($widgetKey === 'score')
                        <div class="col-span-12 lg:col-span-6">
                            @livewire($widget['component'], ['event' => $event, 'tvMode' => true], key('tv-widget-' . $widgetKey))
                        </div>
                    @endif
                @endif
            @endforeach

            {{-- Secondary Metrics Row: Time Remaining (4 cols each) --}}
            @foreach(config('dashboard.tv_default_widgets') as $widgetKey)
                @php
                    $widget = config("dashboard.widgets.{$widgetKey}");
                @endphp

                @if($widget && $widget['tv_visible'] && ($widget['permission'] === null || auth()->user()?->can($widget['permission'])))
                    @if($widgetKey === 'time-remaining')
                        <div class="col-span-12 lg:col-span-4">
                            @livewire($widget['component'], ['event' => $event, 'tvMode' => true], key('tv-widget-' . $widgetKey))
                        </div>
                    @endif
                @endif
            @endforeach

            {{-- Additional secondary metrics (placeholder for rate, progress, etc.) --}}
            <div class="col-span-12 lg:col-span-8">
                {{-- Space for other secondary metrics that will be visible in TV mode --}}
            </div>

            {{-- Data Visualizations Row: Band/Mode Grid (6 cols) + Recent Contacts (6 cols) --}}
            @foreach(config('dashboard.tv_default_widgets') as $widgetKey)
                @php
                    $widget = config("dashboard.widgets.{$widgetKey}");
                @endphp

                @if($widget && $widget['tv_visible'] && ($widget['permission'] === null || auth()->user()?->can($widget['permission'])))
                    @if($widgetKey === 'band-mode-grid')
                        <div class="col-span-12 lg:col-span-6">
                            @livewire($widget['component'], ['event' => $event, 'tvMode' => true], key('tv-widget-' . $widgetKey))
                        </div>
                    @endif

                    @if($widgetKey === 'recent-contacts')
                        <div class="col-span-12 lg:col-span-6">
                            @livewire($widget['component'], ['event' => $event, 'tvMode' => true], key('tv-widget-' . $widgetKey))
                        </div>
                    @endif
                @endif
            @endforeach

            {{-- Progress Goals Widget (full width if present) --}}
            @foreach(config('dashboard.tv_default_widgets') as $widgetKey)
                @php
                    $widget = config("dashboard.widgets.{$widgetKey}");
                @endphp

                @if($widget && $widget['tv_visible'] && ($widget['permission'] === null || auth()->user()?->can($widget['permission'])))
                    @if($widgetKey === 'progress-goals')
                        <div class="col-span-12">
                            @livewire($widget['component'], ['event' => $event, 'tvMode' => true], key('tv-widget-' . $widgetKey))
                        </div>
                    @endif
                @endif
            @endforeach
        </div>
    </div>

    {{-- Connection Status Indicator (bottom right) --}}
    <div class="fixed bottom-6 right-6">
        @livewire('dashboard.connection-status', [
            'isTvMode' => true
        ])
    </div>

    {{-- Keyboard Hint (subtle, bottom left) --}}
    <div
        x-show="!headerVisible"
        x-transition
        class="fixed bottom-6 left-6 text-base-content/30 text-xl"
    >
        Press F for menu
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('tvDashboard', () => ({
        // Header visibility (persisted)
        headerVisible: Alpine.$persist(true).as('tv_dashboard_header_visible').using(localStorage),

        // Auto-refresh failsafe timer
        lastUpdateTime: Date.now(),
        autoRefreshInterval: null,

        init() {
            // Set up auto-refresh failsafe (if no Reverb updates for >1 minute, refresh page)
            this.autoRefreshInterval = setInterval(() => {
                const timeSinceLastUpdate = Date.now() - this.lastUpdateTime;
                const oneMinute = 60 * 1000;

                if (timeSinceLastUpdate > oneMinute) {
                    console.log('TV Dashboard: No updates for >1 minute, auto-refreshing...');
                    window.location.reload();
                }
            }, 30000); // Check every 30 seconds

            // Listen for any Livewire events to update the last update time
            Livewire.on('*', () => {
                this.lastUpdateTime = Date.now();
            });

            // Listen for contact logged events specifically
            window.addEventListener('qso-logged', () => {
                this.lastUpdateTime = Date.now();
            });
        },

        toggleHeader() {
            this.headerVisible = !this.headerVisible;
        },

        destroy() {
            // Clean up interval on component destruction
            if (this.autoRefreshInterval) {
                clearInterval(this.autoRefreshInterval);
            }
        }
    }));
});
</script>
