{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-layouts.guest>
    <x-slot:title>Registration Pending</x-slot:title>

    <div class="text-center space-y-4">
        <div class="flex justify-center">
            <x-icon name="phosphor-clock" class="w-16 h-16 text-warning" />
        </div>

        <h2 class="text-xl font-semibold">Account Pending Approval</h2>

        <p class="text-sm text-base-content/70">
            Your account is awaiting administrator approval.
            You can sign in once an administrator approves it.
        </p>

        <div class="pt-4 border-t">
            <a href="{{ route('login') }}" class="link link-primary text-sm">
                Back to sign in
            </a>
        </div>
    </div>
</x-layouts.guest>
