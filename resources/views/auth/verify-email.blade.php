{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-layouts.guest>
    <x-slot:title>Verify Email</x-slot:title>

    <div class="mb-4 text-sm">
        Verify your email address using the link in your verification email.
    </div>

    @if (session('status') == 'verification-link-sent')
        <x-alert title="A new verification link has been emailed to you." class="mb-4" success />
    @endif

    <div class="space-y-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-button label="Resend Verification Email" type="submit" class="btn-primary w-full" icon="phosphor-paper-plane-tilt" />
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <button type="submit" class="link link-primary text-sm">
                Sign Out
            </button>
        </form>
    </div>
</x-layouts.guest>
