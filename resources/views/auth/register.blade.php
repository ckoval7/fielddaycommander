{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-layouts.guest>
    <x-slot:title>Register</x-slot:title>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        @if(config('auth-security.registration_mode') === 'approval_required')
            <div class="alert alert-info mb-4">
                <x-icon name="phosphor-info" class="w-5 h-5" />
                <span class="text-sm">New accounts require administrator approval before you can sign in.</span>
            </div>
        @endif

        <div class="space-y-4">
            <x-input label="Call Sign" type="text" name="call_sign" :value="old('call_sign')" required autofocus autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" icon="phosphor-megaphone" errorField="call_sign" />
            <x-input label="First Name" type="text" name="first_name" :value="old('first_name')" required autocomplete="given-name" icon="phosphor-user" errorField="first_name" autocapitalize="words" />
            <x-input label="Last Name" type="text" name="last_name" :value="old('last_name')" required autocomplete="family-name" icon="phosphor-user" errorField="last_name" autocapitalize="words" />
            <x-input label="Email" type="email" name="email" :value="old('email')" required autocomplete="email" icon="phosphor-envelope" errorField="email" />
            <x-input label="Password" type="password" name="password" required autocomplete="new-password" icon="phosphor-lock" errorField="password" />
            <x-input label="Confirm Password" type="password" name="password_confirmation" required autocomplete="new-password" icon="phosphor-lock" errorField="password_confirmation" />

            <div class="flex flex-wrap gap-x-6 gap-y-1">
                <label class="flex items-center gap-3 cursor-pointer py-2">
                    <input type="checkbox" class="checkbox checkbox-sm" name="is_youth" value="1" @checked(old('is_youth')) />
                    <span class="text-sm">Youth (age 18 or younger)</span>
                </label>

                <label class="flex items-center gap-3 cursor-pointer py-2">
                    <input type="checkbox" class="checkbox checkbox-sm" name="is_cpr_aed_trained" value="1" @checked(old('is_cpr_aed_trained')) />
                    <span class="text-sm">CPR / AED trained</span>
                </label>
            </div>

            <div class="flex items-center justify-end pt-2">
                <a href="{{ route('login') }}" class="link link-primary text-sm mr-4">
                    Sign in
                </a>

                <x-button label="Register" type="submit" class="btn-primary" icon="phosphor-user-plus" />
            </div>
        </div>
    </form>
</x-layouts.guest>
