{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-layouts.guest>
    {{-- Progress Stepper --}}
    <ul class="steps steps-horizontal w-full mb-8">
        <li class="step {{ $step >= 1 ? 'step-primary' : '' }}">Admin Password</li>
        <li class="step {{ $step >= 2 ? 'step-primary' : '' }}">Site Branding</li>
        <li class="step {{ $step >= 3 ? 'step-primary' : '' }}">Preferences</li>
    </ul>

    <div class="mb-6">
        <h2 class="text-2xl font-bold">Step 2: Site Branding</h2>
    </div>

    <div class="space-y-6">
        <p class="text-center">Customize your site's appearance and identity.</p>

        <form method="POST" action="{{ route('setup.step-2') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <x-input
                label="Site Name"
                name="site_name"
                value="{{ old('site_name', 'Field Day Commander') }}"
                required
                icon="phosphor-buildings"
                errorField="site_name"
                hint="Your organization or club name"
            />

            <x-input
                label="Site Tagline"
                name="site_tagline"
                value="{{ old('site_tagline', 'ARRL Field Day Logging System') }}"
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
                    accept="image/png,image/jpeg,image/svg+xml"
                    class="file-input w-full"
                />
                <span class="block py-1 text-xs">PNG, JPG, or SVG. Maximum 2MB. Recommended: square image, 200x200px or larger</span>
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
