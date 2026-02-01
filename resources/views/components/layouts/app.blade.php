<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.(\App\Models\Setting::get('site_name') ?: config('app.name')) : (\App\Models\Setting::get('site_name') ?: config('app.name')) }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased bg-base-200" x-data x-cloak>

    {{-- NAVBAR mobile only --}}
    <x-nav sticky class="lg:hidden">
        <x-slot:brand>
            <x-app-brand />
        </x-slot:brand>
        <x-slot:actions>
            <x-theme-toggle class="me-2" />
            <x-user-menu class="me-2" />
            <label for="main-drawer" class="lg:hidden me-3">
                <x-icon name="o-bars-3" class="cursor-pointer" />
            </label>
        </x-slot:actions>
    </x-nav>

    {{-- Desktop header - spans full width above sidebar and content --}}
    <div class="hidden lg:block sticky top-0 z-50 bg-base-100 border-b border-base-300">
        <div class="flex items-center justify-between px-6 py-4">
            {{-- Left: App Brand --}}
            <div class="flex items-center gap-4">
                <x-app-brand />
            </div>

            {{-- Right: Theme toggle and User menu --}}
            <div class="flex items-center gap-3">
                <x-theme-toggle />
                <x-user-menu />
            </div>
        </div>
    </div>

    {{-- MAIN --}}
    <x-main full-width>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-inherit">
            {{-- MENU --}}
            <x-menu activate-by-route class="mt-4">
                @auth
                    <x-menu-item title="Dashboard" icon="o-home" link="/" />

                    <x-menu-separator title="LOGGING" />

                    @can('log-contacts')
                        <x-menu-item title="Log Contact" icon="o-pencil-square" link="/contacts/create" />
                    @endcan

                    <x-menu-item title="View Log" icon="o-queue-list" link="/contacts" />

                    <x-menu-separator title="EVENT MANAGEMENT" />

                    <x-menu-item title="Scoring" icon="o-trophy" link="/scoring" />

                    @can('manage-bonuses')
                        <x-menu-item title="Bonuses" icon="o-star" link="/bonuses" />
                    @endcan

                    @can('manage-stations')
                        <x-menu-item title="Stations" icon="o-signal" link="/stations" />
                    @endcan

                    @can('manage-equipment')
                        <x-menu-item title="Equipment" icon="o-wrench-screwdriver" link="/equipment" />
                    @endcan

                    <x-menu-item title="Gallery" icon="o-photo" link="/gallery" />

                    <x-menu-item title="Guestbook" icon="o-book-open" link="/guestbook" />

                    @canany(['manage-events', 'manage-users', 'manage-settings', 'view-reports'])
                        <x-menu-separator title="ADMINISTRATION" />

                        @can('manage-events')
                            <x-menu-item title="Events" icon="o-calendar-days" link="/events" />
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
                    @endcanany
                @else
                    <x-menu-item title="Home" icon="o-home" link="/" />
                    <x-menu-item title="View Log" icon="o-queue-list" link="/contacts" />
                    <x-menu-item title="Gallery" icon="o-photo" link="/gallery" />
                    <x-menu-item title="Guestbook" icon="o-book-open" link="/guestbook" />
                @endauth
            </x-menu>
        </x-slot:sidebar>

        {{-- The `$slot` goes here --}}
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>

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
