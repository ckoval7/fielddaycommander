{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@php
    // The same check the form makes: a running event, or one in its setup window
    $guestbookOpen = app(\App\Services\EventContextService::class)->getOpenGuestbookEvent() !== null;
@endphp

<x-layouts.app>
    <x-slot:title>Sign Our Guestbook</x-slot:title>

    <div class="p-4 sm:p-6 max-sm:px-0">
        {{-- Page Title and Welcome Message --}}
        <div class="mb-8">
            <h1 class="text-3xl sm:text-4xl font-bold mb-3">Sign Our Guestbook</h1>
            <p class="text-base sm:text-lg text-base-content/70 max-w-2xl">
                Thank you for visiting our Field Day event! Please sign our guestbook and share your experience.
            </p>
        </div>

        {{-- No Active Event Message --}}
        @if (! $guestbookOpen)
            <div class="alert alert-info mb-8">
                <x-icon name="phosphor-info" class="w-6 h-6" />
                <div>
                    <h3 class="font-bold">Guestbook Closed</h3>
                    <p>The guestbook is not accepting entries. Please check back later.</p>
                </div>
            </div>
        @else
            {{-- Two-Column Layout for Form and Entries. Each component brings its own card and heading. --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                <livewire:guestbook.guestbook-form />

                <livewire:guestbook.guestbook-list />
            </div>

            {{-- Full-screen guestbook for a shared tablet or laptop at the event --}}
            <div class="flex justify-end mt-4">
                <a
                    href="{{ route('guestbook.kiosk') }}"
                    class="btn btn-ghost btn-sm text-base-content/50 font-normal"
                >
                    <x-icon name="phosphor-monitor" class="w-4 h-4" />
                    Kiosk mode
                </a>
            </div>
        @endif
    </div>
</x-layouts.app>
