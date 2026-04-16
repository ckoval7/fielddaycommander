<x-layouts.app>
    <div class="max-w-7xl mx-auto py-8 px-4">
        <div class="mb-6">
            <h1 class="text-3xl font-bold">Settings</h1>
            <p class="text-gray-600 dark:text-gray-400">Configure system preferences, branding, and roles</p>
        </div>

        <x-tabs selected="system-preferences">
            <x-tab name="system-preferences" label="System Preferences" icon="phosphor-gear-six">
                <livewire:settings.system-preferences />
            </x-tab>

            <x-tab name="branding" label="Site Branding" icon="phosphor-paint-brush">
                <livewire:settings.site-branding />
            </x-tab>

            <x-tab name="roles" label="Role Management" icon="phosphor-shield-check">
                <livewire:settings.role-manager />
            </x-tab>
        </x-tabs>
    </div>
</x-layouts.app>
