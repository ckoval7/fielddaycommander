<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

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

    {{-- HEADER desktop only --}}
    <x-header />

    {{-- MAIN --}}
    <x-main>
        {{-- SIDEBAR --}}
        <x-slot:sidebar drawer="main-drawer" collapsible class="bg-base-100 lg:bg-inherit">

            {{-- BRAND --}}
            <x-app-brand class="px-5 pt-4" />

            {{-- MENU --}}
            <x-menu activate-by-route>
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
</body>
</html>
