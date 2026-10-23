{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="max-w-7xl mx-auto py-8 px-4 max-sm:px-0">
    <div class="mb-6">
        <h1 class="text-3xl font-bold">System Settings</h1>
        <p class="text-gray-600 dark:text-gray-400">Configure system preferences, branding, and roles</p>
    </div>

    <x-tabs wire:model="activeTab">
        @can('manage-settings')
            <x-tab name="system-preferences" label="System Preferences" icon="phosphor-gear-six">
                <livewire:settings.system-preferences />
            </x-tab>

            <x-tab name="branding" label="Site Branding" icon="phosphor-paint-brush">
                <livewire:settings.site-branding />
            </x-tab>
        @endcan

        @can('manage-roles')
            <x-tab name="roles" label="Role Management" icon="phosphor-shield-check">
                <livewire:settings.role-manager />
            </x-tab>
        @endcan
    </x-tabs>
</div>
