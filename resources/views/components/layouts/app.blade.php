<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="reverb-config" content="{{ json_encode(['key' => config('reverb.apps.apps.0.key'), 'host' => config('reverb.apps.apps.0.options.host'), 'port' => config('reverb.apps.apps.0.options.port'), 'scheme' => config('reverb.apps.apps.0.options.scheme')]) }}">
    <title>{{ isset($title) ? $title.' - '.(\App\Models\Setting::get('site_name') ?: config('app.name')) : (\App\Models\Setting::get('site_name') ?: config('app.name')) }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    {{-- Set theme before page renders to prevent flash --}}
    <script>
        (function() {
            let theme = localStorage.getItem('theme');
            // If no saved preference, default to light and save it
            if (!theme) {
                theme = 'light';
                localStorage.setItem('theme', theme);
            }
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased bg-base-200">

    {{-- NAVBAR mobile only --}}
    <x-nav sticky class="lg:hidden !z-20">
        <x-slot:brand>
            <x-app-brand />
        </x-slot:brand>
        <x-slot:actions>
            @auth
                <livewire:components.notification-bell />
            @endauth
            <x-custom-theme-toggle class="me-2" />
            <x-user-menu class="me-2" />
            <button type="button" class="lg:hidden me-3" aria-label="Toggle navigation menu" onclick="document.getElementById('main-drawer').click()">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </button>
        </x-slot:actions>
    </x-nav>

    {{-- Desktop header - spans full width above sidebar and content --}}
    <div class="hidden lg:block sticky top-0 z-50 bg-base-100 border-b border-base-300">
        <div class="flex items-center justify-between px-6 py-4">
            {{-- Left: App Brand --}}
            <div class="flex items-center gap-4">
                <x-app-brand />
            </div>

            {{-- Center: Event Countdown Timer --}}
            <div class="flex-1 flex items-center justify-center">
                <livewire:components.event-countdown />
            </div>

            {{-- Right: Notifications, Theme toggle and User menu --}}
            <div class="flex items-center gap-3">
                @auth
                    <livewire:components.notification-bell />
                @endauth
                <x-custom-theme-toggle />
                <x-user-menu />
            </div>
        </div>
    </div>

    {{-- Mobile timer bar - below navbar --}}
    <div class="lg:hidden sticky top-16 z-10 bg-base-100 border-b border-base-300 px-4 py-2">
        <livewire:components.event-countdown />
    </div>

    {{-- Developer Mode Banner --}}
    @if(config('developer.enabled'))
        <livewire:components.developer-banner />
        @auth
            <livewire:components.dev-role-switcher />
        @endauth
    @endif

    {{-- System Account Banner --}}
    @auth
        <livewire:components.system-account-banner />
    @endauth

    {{-- MAIN --}}
    <x-main full-width with-nav>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-base-200">
            <div
                x-data="{
                    canScrollUp: false,
                    canScrollDown: false,
                    checkScroll() {
                        const el = this.$refs.scrollArea;
                        if (!el) return;
                        this.canScrollUp = el.scrollTop > 10;
                        this.canScrollDown = el.scrollTop + el.clientHeight < el.scrollHeight - 10;
                    }
                }"
                x-init="$nextTick(() => {
                    checkScroll();
                    new ResizeObserver(() => checkScroll()).observe($refs.scrollArea);
                })"
                class="flex flex-col flex-1 min-h-0"
            >
                @auth
                    <div class="mary-hideable px-4 pt-2 pb-3 shrink-0">
                        <livewire:components.event-context-selector />
                    </div>
                    <x-menu-separator />
                @endauth

                {{-- Scroll-up indicator --}}
                <button
                    x-show="canScrollUp"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    @click="$refs.scrollArea.scrollBy({ top: -120, behavior: 'smooth' })"
                    type="button"
                    class="flex justify-center py-1 border-b border-base-300/50 text-base-content/40 hover:text-base-content/70 transition-colors shrink-0"
                    aria-label="Scroll up"
                >
                    <x-icon name="o-chevron-up" class="w-4 h-4" />
                </button>

                {{-- Scrollable menu area --}}
                <div
                    x-ref="scrollArea"
                    @scroll="checkScroll"
                    class="flex-1 overflow-y-auto min-h-0 sidebar-scroll-area"
                >
                    <x-menu activate-by-route class="mt-2">
                    @auth
                        <x-menu-item title="Dashboard" icon="o-home" link="/" />
                        <x-menu-item title="Public Page" icon="o-globe-alt" link="{{ route('public.landing') }}" />
                        <x-menu-item title="Section Map" icon="o-map" link="{{ route('section-map') }}" />

                        <x-menu-separator title="LOGGING" />

                        @can('log-contacts')
                            <x-menu-item title="Log Contact" icon="o-pencil-square" link="{{ route('logging.station-select') }}" exact :active="request()->routeIs('logging.station-select', 'logging.session')" />
                            <x-menu-item title="Transcribe Paper Log" icon="o-clipboard-document" link="{{ route('logging.transcribe.select') }}" :active="request()->routeIs('logging.transcribe.*')" />
                        @endcan

                        <x-menu-item title="View Log" icon="o-queue-list" link="{{ route('logbook.index') }}" />

                        @can('import-contacts')
                            <x-menu-item title="External Loggers" icon="o-signal" link="{{ route('admin.external-loggers') }}" :active="request()->routeIs('admin.external-loggers') || request()->routeIs('admin.import-adif')" />
                        @endcan

                        <x-menu-separator title="EVENT MANAGEMENT" />

                        <x-menu-item title="Scoring" icon="o-trophy" link="/scoring" />

                        @can('manage-bonuses')
                            <x-menu-item title="Bonuses" icon="o-star" link="/bonuses" />
                        @endcan

                        @can('view-stations')
                            <x-menu-item title="Stations" icon="o-server-stack" link="{{ route('stations.index') }}" route="stations.index" />
                        @endcan

                        <x-menu-item title="Shift Schedule" icon="o-calendar-days" link="{{ route('schedule.index') }}" :active="request()->routeIs('schedule.index', 'schedule.my-shifts')" />
                        <x-menu-item title="Site Safety" icon="o-shield-check" link="{{ route('site-safety.index') }}" :active="request()->routeIs('site-safety.index')" />

                        <x-menu-sub title="Equipment" icon="o-wrench-screwdriver">
                            <x-menu-item title="My Catalog" link="{{ route('equipment.index') }}" route="equipment.index" />
                            <x-menu-item title="Club Equipment" link="{{ route('equipment.club') }}" route="equipment.club" />
                            @can('view-all-equipment')
                                <x-menu-item title="All User Catalogs" link="{{ route('equipment.all') }}" route="equipment.all" />
                            @endcan
                        </x-menu-sub>

                        <x-menu-item title="Guestbook" icon="o-book-open" link="/guestbook" :active="request()->routeIs('guestbook.index')" />
                        <x-menu-item title="Gallery" icon="o-photo" link="/gallery" />

                        @php $activeEvent = $activeEvent ?? app(\App\Services\EventContextService::class)->getContextEvent(); @endphp
                        @if($activeEvent)
                            @can('log-contacts')
                                <x-menu-item title="Message Traffic" icon="o-envelope"
                                    link="{{ route('events.messages.index', $activeEvent) }}"
                                    :active="request()->routeIs('events.messages.*')" />
                            @endcan
                            <x-menu-item title="W1AW Bulletin" icon="o-radio"
                                link="{{ route('events.w1aw-bulletin') }}"
                                :active="request()->routeIs('events.w1aw-bulletin')" />
                        @endif

                        @canany(['create-events', 'edit-events', 'manage-users', 'manage-settings', 'manage-shifts', 'view-reports', 'view-security-logs', 'manage-guestbook', 'manage-event-equipment', 'view-all-equipment'])
                            <x-menu-separator title="ADMINISTRATION" />

                            @canany(['create-events', 'edit-events'])
                                <x-menu-item title="Events" icon="o-calendar-days" link="/events" />
                            @endcanany

                            @can('manage-shifts')
                                <x-menu-item title="Manage Schedule" icon="o-cog-6-tooth" link="{{ route('schedule.manage') }}" :active="request()->routeIs('schedule.manage')" />
                            @endcan

                            @can('manage-shifts')
                                <x-menu-item title="Manage Safety Checklist" icon="o-clipboard-document-check" link="{{ route('site-safety.manage') }}" :active="request()->routeIs('site-safety.manage')" />
                            @endcan

                            @can('manage-guestbook')
                                @php $activeEvent = app(\App\Services\EventContextService::class)->getContextEvent(); @endphp
                                @if($activeEvent)
                                    <x-menu-item title="Manage Guestbook" icon="o-book-open" link="{{ route('events.guestbook', $activeEvent->id) }}" :active="request()->routeIs('events.guestbook')" />
                                @endif
                            @endcan

                            @canany(['manage-event-equipment', 'view-all-equipment'])
                                @php $activeEvent = $activeEvent ?? app(\App\Services\EventContextService::class)->getContextEvent(); @endphp
                                @if($activeEvent)
                                    <x-menu-item title="Event Equipment" icon="o-wrench-screwdriver" link="{{ route('events.equipment.dashboard', $activeEvent) }}" :active="request()->routeIs('events.equipment.dashboard')" />
                                @endif
                            @endcanany

                            @can('manage-users')
                                <x-menu-item title="Users" icon="o-user-group" link="/users" />
                            @endcan

                            @can('manage-settings')
                                <x-menu-item title="Settings" icon="o-cog-6-tooth" link="/settings" />
                            @endcan

                            @can('view-reports')
                                <x-menu-item title="Reports" icon="o-document-chart-bar" link="/reports" />
                            @endcan

                            @can('view-security-logs')
                                <x-menu-item title="Audit Logs" icon="o-clipboard-document-list" link="{{ route('admin.audit-logs') }}" :active="request()->routeIs('admin.audit-logs')" />
                            @endcan

                            @if(config('developer.enabled'))
                                @can('manage-settings')
                                    <x-menu-item title="Developer Tools" icon="o-wrench" link="{{ route('admin.developer') }}" :active="request()->routeIs('admin.developer')" />
                                @endcan
                            @endif
                        @endcanany
                    @else
                        <x-menu-item title="Home" icon="o-home" link="/" />
                        <x-menu-item title="Section Map" icon="o-map" link="{{ route('section-map') }}" />
                        <x-menu-item title="View Log" icon="o-queue-list" link="{{ route('logbook.index') }}" />
                        <x-menu-item title="Gallery" icon="o-photo" link="/gallery" />
                        <x-menu-item title="Guestbook" icon="o-book-open" link="/guestbook" />
                    @endauth
                    </x-menu>
                </div>

                {{-- Scroll-down indicator --}}
                <button
                    x-show="canScrollDown"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    @click="$refs.scrollArea.scrollBy({ top: 120, behavior: 'smooth' })"
                    type="button"
                    class="flex justify-center py-1 border-t border-base-300/50 text-base-content/60 hover:text-base-content/90 transition-colors shrink-0"
                    aria-label="Scroll down"
                >
                    <x-icon name="o-chevron-down" class="w-4 h-4" />
                </button>
            </div>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            @auth
                <livewire:components.event-context-banner />
            @endauth
            {{ $slot }}
        </x-slot:content>
    </x-main>

    {{-- FOOTER --}}
    <footer class="border-t border-base-300 bg-base-100 py-4 px-6 text-sm text-base-content/60">
        @php $footerText = \App\Models\Setting::get('site_footer_text'); @endphp
        @if($footerText)
            <p class="text-center mb-2">{{ $footerText }}</p>
        @endif
        <div class="flex flex-col sm:flex-row items-center justify-center gap-x-3 gap-y-1">
            <span>Field Day Commander v{{ config('app.version') }}</span>
            <span class="hidden sm:inline text-base-content/30">&middot;</span>
            <span>Powered by Laravel {{ app()->version() }}</span>
            <span class="hidden sm:inline text-base-content/30">&middot;</span>
            <a href="https://fielddaycommander.org/" target="_blank" rel="noopener noreferrer" class="link link-hover">Website</a>
            <span class="hidden sm:inline text-base-content/30">&middot;</span>
            <a href="https://github.com/ckoval7/fd-commander" target="_blank" rel="noopener noreferrer" class="link link-hover">GitHub</a>
        </div>
    </footer>

    {{-- Sidebar collapsed tooltip portal --}}
    <style>
        #sidebar-tooltip {
            position: fixed;
            z-index: 9999;
            pointer-events: none;
            transform: translateY(-50%);
        }
        #sidebar-tooltip::before {
            content: '';
            position: absolute;
            right: 100%;
            top: 50%;
            transform: translateY(-50%);
            border: 5px solid transparent;
            border-right-color: var(--color-base-300);
        }
    </style>

    <div
        id="sidebar-tooltip"
        style="display:none"
        class="whitespace-nowrap bg-base-300 text-base-content text-sm px-3 py-1.5 rounded-lg shadow-md border border-base-300/50"
    ></div>

    {{--  TOAST area --}}
    <x-toast />

    {{-- Toast notification listener: bridges Livewire "toast" events to MaryUI's window.toast() --}}
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('toast', (event) => {
                const data = Array.isArray(event) ? event[0] : event;

                const iconMap = {
                    'o-check-circle': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>',
                    'o-x-circle': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>',
                    'o-exclamation-triangle': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>',
                    'o-information-circle': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>',
                    'o-envelope': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>',
                    'o-key': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" /></svg>',
                    'o-lock-closed': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>',
                    'o-lock-open': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 1 1 9 0v3.75M3.75 21.75h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H3.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>',
                    'o-trash': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>',
                    'o-clock': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>',
                };

                const cssMap = {
                    success: 'alert-success',
                    error: 'alert-error',
                    warning: 'alert-warning',
                    info: 'alert-info',
                };

                const defaultIcons = {
                    success: 'o-check-circle',
                    error: 'o-x-circle',
                    warning: 'o-exclamation-triangle',
                    info: 'o-information-circle',
                };

                const type = data.type || 'info';
                const iconName = data.icon || defaultIcons[type] || 'o-information-circle';
                const icon = iconMap[iconName] || iconMap['o-information-circle'];
                const css = data.css || cssMap[type] || 'alert-info';

                window.toast({
                    toast: {
                        title: data.title || data.message || '',
                        description: data.description || '',
                        icon: icon,
                        css: css,
                        timeout: data.timeout || 3000,
                    }
                });
            });
        });

        {{-- Show flash-based toasts that survived a redirect --}}
        @if(session('toast'))
            document.addEventListener('livewire:initialized', () => {
                const data = @json(session('toast'));
                Livewire.dispatch('toast', [data]);
            });
        @endif
    </script>
</body>
</html>
