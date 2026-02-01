<x-layouts.guest>
    {{-- Progress Stepper --}}
    <ul class="steps steps-horizontal w-full mb-8">
        <li class="step @if($step >= 1) step-primary @endif">Admin Password</li>
        <li class="step @if($step >= 2) step-primary @endif">Site Branding</li>
        <li class="step @if($step >= 3) step-primary @endif">Preferences</li>
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
                value="{{ old('site_name', 'Field Day Log Database') }}"
                required
                icon="o-building-office"
                hint="Your organization or club name"
            />

            <x-input
                label="Site Tagline"
                name="site_tagline"
                value="{{ old('site_tagline', 'ARRL Field Day Logging System') }}"
                icon="o-chat-bubble-left-ellipsis"
                hint="Optional subtitle or motto"
            />

            <x-file
                label="Site Logo"
                name="logo"
                accept="image/png,image/jpeg,image/svg+xml"
                hint="PNG, JPG, or SVG. Maximum 2MB. Recommended: 800x200px"
            />

            <div class="flex justify-between">
                <x-button
                    type="button"
                    onclick="window.location='{{ route('setup.welcome') }}'"
                    class="btn-ghost"
                    icon="o-arrow-left"
                >
                    Back
                </x-button>

                <x-button type="submit" class="btn-primary" icon="o-arrow-right" icon-right>
                    Next: Preferences
                </x-button>
            </div>
        </form>
    </div>
</x-layouts.guest>
