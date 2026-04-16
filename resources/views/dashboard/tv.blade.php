{{--
TV Dashboard Layout

Large-format dashboard optimized for TV displays and kiosk mode.
Fixed 5-column grid with calculated row heights to fit 1080p screens without scrolling.
Supports kiosk mode via ?kiosk=1 parameter.

Props from controller:
- $title: Dashboard title
- $description: Dashboard description
- $widgets: Collection of widget configurations
- $kiosk: Boolean - kiosk mode enabled
--}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ session('theme', 'light') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="font-sans antialiased bg-base-100">
    {{-- Connection Monitor (Silent in TV Mode) --}}
    <x-dashboard.connection-monitor :tvMode="true" />

    <div
        class="tv-dashboard"
        x-data="{
            kiosk: @js($kiosk),
            fullscreen: $persist(@js($kiosk)).as('tv-dashboard-fullscreen'),
            widgetCount: @js(count($widgets)),
            gridRows: 0,
            widgetHeight: 0,

            init() {
                this.calculateGrid();

                // Recalculate on window resize (debounced)
                let resizeTimer;
                window.addEventListener('resize', () => {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(() => this.calculateGrid(), 200);
                });

                // Global fullscreen keyboard shortcut (F key)
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'f' || e.key === 'F') {
                        e.preventDefault();
                        this.fullscreen = !this.fullscreen;
                        this.kiosk = this.fullscreen;
                    }

                    // ESC key exits kiosk mode
                    if (e.key === 'Escape' && this.kiosk) {
                        this.kiosk = false;
                        this.fullscreen = false;
                    }
                });

                // Auto-enable fullscreen if kiosk param present
                if (this.kiosk && !document.fullscreenElement) {
                    this.requestFullscreen();
                }
            },

            calculateGrid() {
                // 5 columns fixed
                const columns = 5;
                this.gridRows = Math.ceil(this.widgetCount / columns);

                // Calculate widget height to fit screen
                const viewportHeight = window.innerHeight;
                const headerHeight = this.kiosk ? 0 : 80; // Header height when not in kiosk mode
                const padding = 32; // Total vertical padding
                const gap = 24 * (this.gridRows - 1); // Gap between rows

                const availableHeight = viewportHeight - headerHeight - padding - gap;
                this.widgetHeight = Math.floor(availableHeight / this.gridRows);
            },

            requestFullscreen() {
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen().catch(err => {
                        console.log('Fullscreen request failed:', err);
                    });
                }
            }
        }"
        :class="{ 'kiosk-mode': kiosk }"
        x-init="calculateGrid"
    >
        {{-- TV Dashboard Header (hidden in kiosk mode) --}}
        <div
            x-show="!kiosk"
            x-transition
            class="bg-base-200 border-b border-base-300 px-6 py-4"
        >
            <div class="container mx-auto flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-base-content">{{ $title }}</h1>
                    @if($description)
                        <p class="text-lg text-base-content/70 mt-1">{{ $description }}</p>
                    @endif
                </div>

                <div class="flex items-center gap-4">
                    {{-- Event Countdown Timer --}}
                    @php
                        $activeEvent = \App\Models\Event::active()->first();
                    @endphp
                    @if($activeEvent)
                        <div
                            x-data="{
                                endTime: new Date(@js($activeEvent->end_time->toIso8601String())).getTime(),
                                serverNow: new Date(@js(appNow()->toIso8601String())).getTime(),
                                timeLeft: '',

                                init() {
                                    this.updateTimer();
                                    setInterval(() => {
                                        this.serverNow += 1000;
                                        this.updateTimer();
                                    }, 1000);
                                },

                                updateTimer() {
                                    const diff = this.endTime - this.serverNow;

                                    if (diff <= 0) {
                                        this.timeLeft = 'Event Ended';
                                        return;
                                    }

                                    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                                    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

                                    if (days > 0) {
                                        this.timeLeft = `${days}d ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                                    } else {
                                        this.timeLeft = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                                    }
                                }
                            }"
                            class="badge badge-lg badge-primary gap-2 px-4 py-3"
                        >
                            <x-icon name="phosphor-clock" class="w-5 h-5" />
                            <span class="font-mono text-lg font-bold" x-text="timeLeft"></span>
                        </div>
                    @endif

                    {{-- Fullscreen Toggle --}}
                    <button
                        @click="fullscreen = !fullscreen; kiosk = fullscreen; if (fullscreen) requestFullscreen();"
                        class="btn btn-ghost btn-sm gap-2"
                    >
                        <x-icon :name="$kiosk ? 'o-arrows-pointing-in' : 'o-arrows-pointing-out'" class="w-5 h-5" />
                        <span x-text="fullscreen ? 'Exit Fullscreen (F)' : 'Fullscreen (F)'"></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- TV Widget Grid --}}
        <div class="tv-dashboard-content" :class="{ 'px-4 py-4': !kiosk, 'p-0': kiosk }">
            @if($widgets && count($widgets) > 0)
                <div
                    class="tv-widget-grid"
                    :style="`grid-template-rows: repeat(${gridRows}, ${widgetHeight}px);`"
                >
                    @foreach($widgets as $index => $widget)
                        @if($widget['visible'] ?? true)
                            <div
                                class="tv-widget-item"
                                wire:key="tv-widget-{{ $widget['id'] }}"
                                :style="`min-height: ${widgetHeight}px;`"
                            >
                                {{-- Render Widget Component in TV size --}}
                                @switch($widget['type'])
                                    @case('stat_card')
                                        <livewire:dashboard.widgets.stat-card
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="tv"
                                            wire:key="tv-stat-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('chart')
                                        <livewire:dashboard.widgets.chart
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="tv"
                                            wire:key="tv-chart-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('progress_bar')
                                        <x-card class="h-full flex items-center justify-center">
                                            <livewire:dashboard.widgets.progress-bar
                                                :config="$widget['config']"
                                                :widget-id="$widget['id']"
                                                size="tv"
                                                wire:key="tv-progress-{{ $widget['id'] }}"
                                            />
                                        </x-card>
                                        @break

                                    @case('list_widget')
                                        <livewire:dashboard.widgets.list-widget
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="tv"
                                            wire:key="tv-list-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('timer')
                                        <x-card class="h-full flex items-center justify-center">
                                            <livewire:dashboard.widgets.timer
                                                :config="$widget['config']"
                                                :widget-id="$widget['id']"
                                                size="tv"
                                                wire:key="tv-timer-{{ $widget['id'] }}"
                                            />
                                        </x-card>
                                        @break

                                    @case('info_card')
                                        <livewire:dashboard.widgets.info-card
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="tv"
                                            wire:key="tv-info-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('feed')
                                        <livewire:dashboard.widgets.feed
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="tv"
                                            wire:key="tv-feed-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @case('sections_worked')
                                        <livewire:dashboard.widgets.sections-worked
                                            :config="$widget['config']"
                                            :widget-id="$widget['id']"
                                            size="tv"
                                            wire:key="tv-sections-{{ $widget['id'] }}"
                                        />
                                        @break

                                    @default
                                        <x-card class="h-full flex items-center justify-center">
                                            <div class="text-center text-base-content/50">
                                                <x-icon name="phosphor-question" class="w-16 h-16 mx-auto mb-4" />
                                                <p class="text-xl">Unknown widget type: {{ $widget['type'] }}</p>
                                            </div>
                                        </x-card>
                                @endswitch
                            </div>
                        @endif
                    @endforeach
                </div>
            @else
                {{-- Empty State --}}
                <div class="flex flex-col items-center justify-center h-screen text-center">
                    <x-icon name="phosphor-television" class="w-32 h-32 text-base-content/30 mb-6" />
                    <h2 class="text-4xl font-bold text-base-content mb-4">No widgets configured</h2>
                    <p class="text-2xl text-base-content/60">Configure widgets in the main dashboard to display here</p>
                </div>
            @endif
        </div>

        {{-- Kiosk Mode Indicator (bottom corner) --}}
        <div
            x-show="kiosk"
            x-transition
            class="fixed bottom-4 left-4 bg-base-300/90 backdrop-blur-sm text-base-content/70 px-3 py-1 rounded-lg text-xs font-medium shadow-lg"
        >
            <span class="inline-flex items-center gap-1.5">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-success"></span>
                </span>
                Kiosk Mode • Press F or ESC to exit
            </span>
        </div>
    </div>

    <style>
        /* TV Dashboard Styles */
        .tv-dashboard {
            min-height: 100vh;
            background: oklch(var(--b1));
        }

        .tv-dashboard.kiosk-mode {
            position: fixed;
            inset: 0;
            overflow: hidden;
        }

        .tv-dashboard-content {
            height: calc(100vh - 80px); /* Subtract header height */
        }

        .kiosk-mode .tv-dashboard-content {
            height: 100vh;
            padding: 1rem !important;
        }

        /* TV Widget Grid - 5 columns fixed */
        .tv-widget-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1.5rem;
            width: 100%;
            height: 100%;
        }

        .tv-widget-item {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: tv-fade-in 0.5s ease-out;
            animation-fill-mode: both;
        }

        /* Stagger animation for TV widgets */
        .tv-widget-item:nth-child(1) { animation-delay: 0.08s; }
        .tv-widget-item:nth-child(2) { animation-delay: 0.16s; }
        .tv-widget-item:nth-child(3) { animation-delay: 0.24s; }
        .tv-widget-item:nth-child(4) { animation-delay: 0.32s; }
        .tv-widget-item:nth-child(5) { animation-delay: 0.40s; }
        .tv-widget-item:nth-child(6) { animation-delay: 0.48s; }
        .tv-widget-item:nth-child(7) { animation-delay: 0.56s; }
        .tv-widget-item:nth-child(8) { animation-delay: 0.64s; }
        .tv-widget-item:nth-child(9) { animation-delay: 0.72s; }
        .tv-widget-item:nth-child(10) { animation-delay: 0.80s; }
        .tv-widget-item:nth-child(11) { animation-delay: 0.88s; }
        .tv-widget-item:nth-child(12) { animation-delay: 0.96s; }
        .tv-widget-item:nth-child(13) { animation-delay: 1.04s; }
        .tv-widget-item:nth-child(14) { animation-delay: 1.12s; }
        .tv-widget-item:nth-child(15) { animation-delay: 1.20s; }
        .tv-widget-item:nth-child(16) { animation-delay: 1.28s; }
        .tv-widget-item:nth-child(17) { animation-delay: 1.36s; }
        .tv-widget-item:nth-child(18) { animation-delay: 1.44s; }
        .tv-widget-item:nth-child(19) { animation-delay: 1.52s; }
        .tv-widget-item:nth-child(20) { animation-delay: 1.60s; }

        @keyframes tv-fade-in {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* TV-specific text sizing (larger, bolder) */
        .tv-dashboard {
            font-size: 1.125rem; /* 18px base */
        }

        .tv-dashboard h1 {
            font-size: 2.5rem;
            font-weight: 800;
        }

        .tv-dashboard h2 {
            font-size: 2rem;
            font-weight: 700;
        }

        .tv-dashboard h3 {
            font-size: 1.5rem;
            font-weight: 600;
        }

        /* High contrast for TV viewing */
        .tv-dashboard .card {
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.15), 0 2px 4px -2px rgb(0 0 0 / 0.15);
            border: 1px solid oklch(var(--bc) / 0.1);
        }

        /* Ensure widgets fill their containers */
        .tv-widget-item > * {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        /* Prevent text selection and context menus in kiosk mode */
        .kiosk-mode {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
        }

        .kiosk-mode * {
            cursor: default !important;
        }
    </style>

    @livewireScripts
</body>
</html>
