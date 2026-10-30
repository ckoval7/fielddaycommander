{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-layouts.guest own-heading>
    <x-slot:title>Step 2: Site Branding</x-slot:title>
    {{-- Progress Stepper --}}
    <ul class="steps steps-horizontal w-full mb-8">
        <li class="step {{ $step >= 1 ? 'step-primary' : '' }}">Admin Password</li>
        <li class="step {{ $step >= 2 ? 'step-primary' : '' }}">Site Branding</li>
        <li class="step {{ $step >= 3 ? 'step-primary' : '' }}">Preferences</li>
    </ul>

    <div class="mb-6">
        <h1 class="text-2xl font-bold">Step 2: Site Branding</h1>
    </div>

    <div class="space-y-6">
        <p class="text-center">Choose the name and logo shown on your site.</p>

        <form method="POST" action="{{ route('setup.step-2') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <x-input
                autocapitalize="words"
                label="Site Name"
                name="site_name"
                value="{{ old('site_name', 'Field Day Commander') }}"
                required
                icon="phosphor-buildings"
                errorField="site_name"
                hint="Your club or organization name"
            />

            <x-input
                autocapitalize="sentences"
                label="Site Tagline"
                name="site_tagline"
                value="{{ old('site_tagline', 'Field Day Logging and Scoring') }}"
                icon="phosphor-chat-centered-dots"
                errorField="site_tagline"
                hint="Optional subtitle or motto"
            />

            <div class="flex flex-col w-full">
                <label for="logo-input" class="block py-1">
                    <span class="text-sm">Site Logo</span>
                </label>
                <input
                    type="file"
                    name="logo"
                    id="logo-input"
                    accept="image/png,image/jpeg"
                    class="file-input w-full"
                />
                <span class="block py-1 text-xs">Optional. PNG or JPG, up to 2 MB. Recommended: a square image, at least 200 × 200 pixels.</span>
            </div>

            <div class="flex justify-between">
                <x-button
                    type="button"
                    onclick="window.location='{{ route('setup.welcome') }}'"
                    onkeydown="if(event.key==='Enter'||event.key===' '){window.location='{{ route('setup.welcome') }}'}"
                    class="btn-ghost"
                    icon="phosphor-arrow-left"
                >
                    Back
                </x-button>

                <x-button type="submit" class="btn-primary" icon-right="phosphor-arrow-right">
                    Next: Preferences
                </x-button>
            </div>
        </form>
    </div>
</x-layouts.guest>
