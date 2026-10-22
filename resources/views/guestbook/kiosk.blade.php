{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Guestbook Kiosk

The guestbook on a shared tablet or laptop at the event site: no header or
sidebar, the form at the top and the latest entries below it, newest first.
Like the TV dashboard it reloads itself on a 419 rather than leaving the page.
--}}
@php
    $activeEvent = \App\Models\Event::active()->with('eventConfiguration')->first()
        ?? \App\Models\Event::inSetupWindow()->orderBy('start_time')->with('eventConfiguration')->first();
    $guestbookOpen = $activeEvent?->eventConfiguration?->guestbook_enabled;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-reload-on-session-expiry>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-reverb-config-meta />
    <title>Sign Our Guestbook - {{ \App\Models\Setting::get('site_name') ?: config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <x-pwa-meta />

    <script>
        (function() {
            const theme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#1d232a' : '#ffffff');
        })();
    </script>

    <x-silence-livewire-rejections />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh font-sans antialiased bg-base-200">
    <main class="mx-auto max-w-6xl space-y-6 pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))] pl-[max(0.75rem,env(safe-area-inset-left))] pr-[max(0.75rem,env(safe-area-inset-right))] sm:pl-[max(1.5rem,env(safe-area-inset-left))] sm:pr-[max(1.5rem,env(safe-area-inset-right))]">
        @if (! $guestbookOpen)
            <div class="alert alert-info">
                <x-icon name="phosphor-info" class="w-6 h-6" />
                <div>
                    <h1 class="font-bold">No Active Event</h1>
                    <p>Currently, there is no active Field Day event accepting guestbook entries. Please check back later!</p>
                </div>
            </div>
        @else
            <section class="mx-auto max-w-3xl">
                <livewire:guestbook.guestbook-form kiosk />
            </section>

            <section>
                <livewire:guestbook.guestbook-list />
            </section>
        @endif

        <div class="flex justify-end">
            <a
                href="{{ route('guestbook.index') }}"
                class="btn btn-ghost btn-sm text-base-content/50 font-normal"
            >
                <x-icon name="phosphor-sign-out" class="w-4 h-4" />
                Exit kiosk
            </a>
        </div>
    </main>

    <x-form-error-summary />
</body>
</html>
