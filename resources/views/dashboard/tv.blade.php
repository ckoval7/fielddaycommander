{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
TV Dashboard Layout

Large-format dashboard optimized for TV displays and kiosk mode.
Fixed grid sized from the config: `columns` columns, rows derived from the
widgets' col_span/row_span, every row an equal share of the viewport so the
board fits one screen without scrolling.

Unattended-display behaviour:
- F (configurable) toggles real browser fullscreen; Esc exits it.
- ?kiosk=1 applies kiosk styling (no cursor/selection, hint pill).
- A 419 reloads this page instead of leaving it (see app.js).
- If no Livewire request has succeeded for a while, the page checks the
  health endpoint and reloads once the server answers, so a TV recovers
  from a restart or network drop without someone touching it.

Props from controller:
- $title, $description: Dashboard title / description
- $widgets: Visible widget configurations, ordered, spans resolved
- $columns, $rows: Grid dimensions
- $kiosk: Boolean - kiosk mode enabled
- $kioskShortcut: Fullscreen toggle key
- $showKioskHint: Whether to show the kiosk hint pill
--}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ session('theme', 'light') }}" data-reload-on-session-expiry>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <x-reverb-config-meta />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} - {{ config('app.name') }}</title>

    <x-silence-livewire-rejections />
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
            isFullscreen: false,
            showHint: false,
            shortcut: @js($kioskShortcut),
            showKioskHint: @js($showKioskHint),
            lastSuccessfulRequestAt: Date.now(),
            staleAfterMs: 120000,
            watchdogId: null,
            hintTimer: null,
            keydownHandler: null,
            fullscreenHandler: null,
            removeRequestInterceptor: null,

            init() {
                this.keydownHandler = (e) => {
                    if (e.key.toLowerCase() === this.shortcut && !e.ctrlKey && !e.metaKey && !e.altKey) {
                        e.preventDefault();
                        this.toggleFullscreen();
                    }
                };
                document.addEventListener('keydown', this.keydownHandler);

                // Browsers swallow Esc while exiting fullscreen, so follow the
                // fullscreen state itself rather than key presses.
                this.fullscreenHandler = () => {
                    this.isFullscreen = !!document.fullscreenElement;
                    this.flashHint();
                };
                document.addEventListener('fullscreenchange', this.fullscreenHandler);

                this.removeRequestInterceptor = Livewire.interceptRequest(({ onSuccess }) => {
                    onSuccess(() => {
                        this.lastSuccessfulRequestAt = Date.now();
                    });
                });
                this.watchdogId = setInterval(() => this.checkForStaleness(), 30000);

                this.flashHint();
            },

            destroy() {
                document.removeEventListener('keydown', this.keydownHandler);
                document.removeEventListener('fullscreenchange', this.fullscreenHandler);
                if (typeof this.removeRequestInterceptor === 'function') {
                    this.removeRequestInterceptor();
                }
                clearInterval(this.watchdogId);
                clearTimeout(this.hintTimer);
            },

            get kioskStyling() {
                return this.kiosk || this.isFullscreen;
            },

            toggleFullscreen() {
                if (document.fullscreenElement) {
                    document.exitFullscreen?.();
                    return;
                }

                document.documentElement.requestFullscreen?.().catch((err) => {
                    console.warn('TV Dashboard: fullscreen request failed', err);
                });
            },

            flashHint() {
                if (!this.showKioskHint || !this.kioskStyling) {
                    this.showHint = false;
                    return;
                }

                this.showHint = true;
                clearTimeout(this.hintTimer);
                this.hintTimer = setTimeout(() => { this.showHint = false; }, 6000);
            },

            // Widgets poll every 10-30s; a long gap without a successful
            // request means the page lost the server. Reload only once the
            // health check answers, so a reload never strands the TV on a
            // browser error page.
            async checkForStaleness() {
                if (document.hidden || Date.now() - this.lastSuccessfulRequestAt < this.staleAfterMs) {
                    return;
                }

                try {
                    const response = await fetch('/up', { cache: 'no-store' });
                    if (response.ok) {
                        window.location.reload();
                    }
                } catch {
                    // Server still unreachable; try again on the next tick.
                }
            },
        }"
        :class="{ 'kiosk-mode': kioskStyling }"
    >
        <div class="tv-dashboard-content">
            @if($widgets->isNotEmpty())
                <div
                    class="tv-widget-grid"
                    style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr)); grid-template-rows: repeat({{ $rows }}, minmax(0, 1fr));"
                >
                    @foreach($widgets as $widget)
                        <div
                            class="tv-widget-item"
                            style="grid-column: span {{ $widget['col_span'] }} / span {{ $widget['col_span'] }}; grid-row: span {{ $widget['row_span'] }} / span {{ $widget['row_span'] }};"
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
                                    <livewire:dashboard.widgets.progress-bar
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="tv"
                                        wire:key="tv-progress-{{ $widget['id'] }}"
                                    />
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
                                    <livewire:dashboard.widgets.timer
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="tv"
                                        wire:key="tv-timer-{{ $widget['id'] }}"
                                    />
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
                    @endforeach
                </div>
            @else
                {{-- Empty State --}}
                <div class="flex flex-col items-center justify-center h-full text-center">
                    <x-icon name="phosphor-television" class="w-32 h-32 text-base-content/30 mb-6" />
                    <h2 class="text-4xl font-bold text-base-content mb-4">No widgets configured</h2>
                    <p class="text-2xl text-base-content/60">No widgets are set up for the TV dashboard.</p>
                </div>
            @endif
        </div>

        {{-- Kiosk hint (bottom corner, fades after a few seconds) --}}
        <div
            x-show="showHint"
            x-transition
            x-cloak
            class="fixed bottom-4 left-4 bg-base-300/90 backdrop-blur-sm text-base-content/70 px-3 py-1 rounded-lg text-xs font-medium shadow-lg"
        >
            <span class="inline-flex items-center gap-1.5">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-success"></span>
                </span>
                <span x-text="isFullscreen ? `Press ${shortcut.toUpperCase()} or Esc to exit fullscreen` : `Press ${shortcut.toUpperCase()} for fullscreen`"></span>
            </span>
        </div>
    </div>

    <style>
        /* TV Dashboard Styles */
        .tv-dashboard {
            min-height: 100vh;
            min-height: 100dvh;
            background: var(--color-base-100);
            font-size: 1.125rem; /* 18px base */
        }

        .tv-dashboard.kiosk-mode {
            position: fixed;
            inset: 0;
            overflow: hidden;
        }

        .tv-dashboard-content {
            height: 100vh;
            height: 100dvh;
            padding: 1rem;
        }

        .tv-widget-grid {
            display: grid;
            gap: 1.5rem;
            width: 100%;
            height: 100%;
        }

        .tv-widget-item {
            display: flex;
            flex-direction: column;
            min-height: 0;
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

        /* High contrast for TV viewing */
        .tv-dashboard .card {
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.15), 0 2px 4px -2px rgb(0 0 0 / 0.15);
            border: 1px solid color-mix(in oklab, var(--color-base-content) 10%, transparent);
        }

        /* Ensure widgets fill their containers */
        .tv-widget-item > * {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        /* Prevent text selection and pointer affordances in kiosk mode */
        .kiosk-mode {
            user-select: none;
            -webkit-user-select: none;
        }

        .kiosk-mode * {
            cursor: default !important;
        }
    </style>

    @livewireScripts
</body>
</html>
