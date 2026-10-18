{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-layouts.guest>
    <x-slot:title>Sign Out</x-slot:title>

    <p class="mb-4 text-sm">
        You're signed in as {{ auth()->user()->call_sign }}. Sign out to end your session on this browser.
    </p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf

        <div class="flex items-center justify-between pt-2">
            <a href="{{ route('dashboard') }}" class="link link-primary text-sm">Back to the dashboard</a>

            <x-button label="Sign Out" type="submit" class="btn-primary" icon="phosphor-sign-out" />
        </div>
    </form>
</x-layouts.guest>
