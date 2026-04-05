<div class="space-y-6">
    <x-card>
        <x-slot:title>Site Identity</x-slot:title>

        <div class="space-y-4">
            <x-input
                label="Site Name"
                wire:model="site_name"
                required
                icon="o-building-office"
                hint="Your organization or club name"
            />

            <x-input
                label="Site Tagline"
                wire:model="site_tagline"
                icon="o-chat-bubble-left-ellipsis"
                hint="Optional subtitle or motto"
            />
        </div>
    </x-card>

    <x-card>
        <x-slot:title>Logo</x-slot:title>

        <div class="space-y-4">
            @if($logo_path)
                <div>
                    <span class="block text-sm font-medium mb-2">Current Logo</span>
                    <div class="flex items-center gap-4">
                        <img src="{{ Storage::url($logo_path) }}" alt="Site Logo" class="max-h-24 border rounded">
                        <x-button
                            wire:click="removeLogo"
                            class="btn-error btn-sm"
                            icon="o-trash"
                            spinner="removeLogo"
                        >
                            <span wire:loading.remove wire:target="removeLogo">Remove Logo</span>
                            <span wire:loading wire:target="removeLogo">Removing...</span>
                        </x-button>
                    </div>
                </div>
            @endif

            <x-file
                label="Upload New Logo"
                wire:model="new_logo"
                accept="image/png,image/jpeg,image/svg+xml"
                hint="PNG, JPG, or SVG. Maximum 2MB. Recommended: 800x200px"
            >
                <x-slot:append>
                    <div wire:loading wire:target="new_logo" class="text-sm text-primary">
                        Uploading...
                    </div>
                </x-slot:append>
            </x-file>

            @if($new_logo)
                <div>
                    <span class="block text-sm font-medium mb-2">Preview</span>
                    <img src="{{ $new_logo->temporaryUrl() }}" alt="Logo Preview" class="max-h-24 border rounded">
                </div>
            @endif
        </div>
    </x-card>

    <x-card>
        <x-slot:title>Footer</x-slot:title>

        <x-textarea
            label="Footer Text"
            wire:model="footer_text"
            rows="3"
            hint="Copyright notice, club information, etc. (max 500 characters)"
        />
    </x-card>

    <x-card>
        <x-slot:title>Public Landing Page</x-slot:title>

        <div class="space-y-4">
            <x-textarea
                label="Welcome Message"
                wire:model="welcome_message"
                rows="4"
                hint="Displayed on the public landing page. Tell visitors about your club or operation. (max 2000 characters)"
            />

            <a href="{{ route('public.landing') }}" target="_blank" class="btn btn-sm btn-ghost gap-2">
                <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                View Public Landing Page
            </a>
        </div>
    </x-card>

    <div class="flex justify-end">
        <x-button
            wire:click="save"
            class="btn-primary"
            icon="o-check"
            spinner="save"
        >
            <span wire:loading.remove wire:target="save">Save Branding</span>
            <span wire:loading wire:target="save">Saving...</span>
        </x-button>
    </div>
</div>
