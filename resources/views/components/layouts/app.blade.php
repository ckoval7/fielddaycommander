<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
    <x-nav sticky class="lg:hidden z-50">
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
    <div class="lg:hidden sticky top-16 z-40 bg-base-100 border-b border-base-300 px-4 py-2">
        <livewire:components.event-countdown />
    </div>

    {{-- Developer Mode Banner --}}
    @if(config('developer.enabled'))
        <livewire:components.developer-banner />
        @auth
            <livewire:components.dev-role-switcher />
        @endauth
    @endif

    {{-- MAIN --}}
    <x-main full-width with-nav>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-inherit">
            @auth
                <div class="px-4 pt-2 pb-3">
                    <livewire:components.event-context-selector />
                </div>
                <x-menu-separator />
            @endauth

            {{-- MENU --}}
            <x-menu activate-by-route class="mt-4">
                @auth
                    <x-menu-item title="Dashboard" icon="o-home" link="/" />

                    <x-menu-separator title="LOGGING" />

                    @can('log-contacts')
                        <x-menu-item title="Log Contact" icon="o-pencil-square" link="{{ route('logging.station-select') }}" exact :active="request()->routeIs('logging.station-select', 'logging.session')" />
                        <x-menu-item title="Transcribe Paper Log" icon="o-clipboard-document" link="{{ route('logging.transcribe.select') }}" :active="request()->routeIs('logging.transcribe.*')" />
                    @endcan

                    <x-menu-item title="View Log" icon="o-queue-list" link="{{ route('logbook.index') }}" />

                    <x-menu-separator title="EVENT MANAGEMENT" />

                    <x-menu-item title="Scoring" icon="o-trophy" link="/scoring" />

                    @can('manage-bonuses')
                        <x-menu-item title="Bonuses" icon="o-star" link="/bonuses" />
                    @endcan

                    @can('view-stations')
                        <x-menu-item title="Stations" icon="o-server-stack" link="{{ route('stations.index') }}" route="stations.index" />
                    @endcan

                    <x-menu-item title="Schedule" icon="o-calendar-days" link="{{ route('schedule.index') }}" :active="request()->routeIs('schedule.index', 'schedule.my-shifts')" />
                    <x-menu-item title="Site Safety" icon="o-shield-check" link="{{ route('site-safety.index') }}" :active="request()->routeIs('site-safety.index')" />

                    <x-menu-sub title="Equipment" icon="o-wrench-screwdriver">
                        <x-menu-item title="My Catalog" link="{{ route('equipment.index') }}" route="equipment.index" />
                        <x-menu-item title="Event Commitments" link="{{ route('equipment.events') }}" route="equipment.events" />
                    </x-menu-sub>

                    <x-menu-item title="Guestbook" icon="o-book-open" link="/guestbook" :active="request()->routeIs('guestbook.index')" />
                    <x-menu-item title="Gallery" icon="o-photo" link="/gallery" />

                    @can('manage-guestbook')
                        @php $activeEvent = app(\App\Services\EventContextService::class)->getContextEvent(); @endphp
                        @if($activeEvent)
                            <x-menu-item title="Manage Guestbook" icon="o-clipboard-document-list" link="{{ route('events.guestbook', $activeEvent->id) }}" :active="request()->routeIs('events.guestbook')" />
                        @endif
                    @endcan

                    @can('log-contacts')
                        @php $activeEvent = $activeEvent ?? app(\App\Services\EventContextService::class)->getContextEvent(); @endphp
                        @if($activeEvent)
                            <x-menu-item title="Message Traffic" icon="o-envelope"
                                link="{{ route('events.messages.index', $activeEvent) }}"
                                :active="request()->routeIs('events.messages.*', 'events.w1aw-bulletin')" />
                        @endif
                    @endcan

                    @canany(['manage-events', 'manage-users', 'manage-settings', 'manage-shifts', 'view-reports', 'view-security-logs'])
                        <x-menu-separator title="ADMINISTRATION" />

                        @can('manage-events')
                            <x-menu-item title="Events" icon="o-calendar-days" link="/events" />
                        @endcan

                        @can('manage-shifts')
                            <x-menu-item title="Manage Schedule" icon="o-cog-6-tooth" link="{{ route('schedule.manage') }}" :active="request()->routeIs('schedule.manage')" />
                        @endcan

                        @can('manage-shifts')
                            <x-menu-item title="Manage Safety Checklist" icon="o-clipboard-document-check" link="{{ route('site-safety.manage') }}" :active="request()->routeIs('site-safety.manage')" />
                        @endcan

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
                    <x-menu-item title="View Log" icon="o-queue-list" link="{{ route('logbook.index') }}" />
                    <x-menu-item title="Gallery" icon="o-photo" link="/gallery" />
                    <x-menu-item title="Guestbook" icon="o-book-open" link="/guestbook" />
                @endauth
            </x-menu>
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
            <span>FD Log DB v{{ config('app.version') }}</span>
            <span class="hidden sm:inline text-base-content/30">&middot;</span>
            <span>Powered by Laravel {{ app()->version() }}</span>
        </div>
    </footer>

    {{--  TOAST area --}}
    <x-toast />

    {{-- Toast notification listener --}}
    <script>
        document.addEventListener('livewire:initialized', () => {
            Livewire.on('notify', (event) => {
                const isError = event.title.toLowerCase().includes('error');
                const iconSvg = isError
                    ? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>';

                window.toast({
                    toast: {
                        title: event.title,
                        description: event.description,
                        icon: iconSvg,
                        css: isError ? 'alert-error' : 'alert-success',
                        timeout: 3000
                    }
                });
            });
        });
    </script>
</body>
</html>
