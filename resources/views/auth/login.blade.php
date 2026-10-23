{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-layouts.guest>
    <x-slot:title>Sign In</x-slot:title>

    @if (session('error'))
        <x-alert :title="session('error')" class="mb-4 alert-warning" icon="phosphor-warning" />
    @endif

    @if (session('status'))
        <x-alert :title="session('status')" class="mb-4" />
    @elseif ($accountMessage = \App\Http\Middleware\EnsureAccountNotLocked::messageFor(request()->query(\App\Http\Middleware\EnsureAccountNotLocked::REASON_PARAMETER)))
        <x-alert :title="$accountMessage" class="mb-4 alert-warning" icon="phosphor-lock" />
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="space-y-4">
            <x-input label="Email" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" icon="phosphor-envelope" errorField="email" />
            <x-input label="Password" type="password" name="password" required autocomplete="current-password" icon="phosphor-lock" errorField="password" />
            <x-checkbox label="Remember me" name="remember" />

            <div class="flex items-center justify-between pt-2">
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="link link-primary text-sm">
                        Forgot password?
                    </a>
                @endif

                <x-button label="Sign In" type="submit" class="btn-primary" icon="phosphor-sign-in" />
            </div>

            @if (config('auth-security.registration_mode') !== 'disabled')
                <div class="text-center pt-4 border-t">
                    <span class="text-sm">Need an account?</span>
                    <a href="{{ route('register') }}" class="link link-primary text-sm ml-1">Register</a>
                </div>
            @endif
        </div>
    </form>
</x-layouts.guest>
