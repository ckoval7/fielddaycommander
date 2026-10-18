{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-layouts.guest>
    <x-slot:title>Reset Password</x-slot:title>

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="space-y-4">
            <x-input label="Email" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="email" icon="phosphor-envelope" errorField="email" />
            <x-input label="New Password" type="password" name="password" required autocomplete="new-password" icon="phosphor-lock" errorField="password" />
            <x-input label="Confirm Password" type="password" name="password_confirmation" required autocomplete="new-password" icon="phosphor-lock" errorField="password_confirmation" />

            <div class="pt-2">
                <x-button label="Reset Password" type="submit" class="btn-primary w-full" icon="phosphor-key" />
            </div>
        </div>
    </form>
</x-layouts.guest>
