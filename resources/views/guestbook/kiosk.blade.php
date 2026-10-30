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
    // The same check the form makes: a running event, or one in its setup window
    $guestbookOpen = app(\App\Services\EventContextService::class)->getOpenGuestbookEvent() !== null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-reload-on-session-expiry>
<head>
    <title>Sign Our Guestbook - {{ \App\Models\Setting::get('site_name') ?: config('app.name') }}</title>
    <x-layouts.head viewport-fit />
</head>
<body class="min-h-dvh font-sans antialiased bg-base-200">
    <main class="mx-auto max-w-6xl space-y-6 pt-[max(1rem,env(safe-area-inset-top))] pb-[max(1rem,env(safe-area-inset-bottom))] pl-[max(0.75rem,env(safe-area-inset-left))] pr-[max(0.75rem,env(safe-area-inset-right))] sm:pl-[max(1.5rem,env(safe-area-inset-left))] sm:pr-[max(1.5rem,env(safe-area-inset-right))]">
        @if (! $guestbookOpen)
            <div class="alert alert-info">
                <x-icon name="phosphor-info" class="w-6 h-6" />
                <div>
                    <h1 class="font-bold">Guestbook Closed</h1>
                    <p>The guestbook is not accepting entries. Please check back later.</p>
                </div>
            </div>
        @else
            <h1 class="text-3xl font-bold">Sign Our Guestbook</h1>
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
                class="btn btn-ghost btn-sm text-base-content/70 font-normal"
            >
                <x-icon name="phosphor-sign-out" class="w-4 h-4" />
                Exit kiosk
            </a>
        </div>
    </main>

    <x-toast />
    <x-form-error-summary />
    <x-confirm-dialog />
</body>
</html>
