{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-alert icon="phosphor-check-circle" class="alert-info mb-4">
    <div class="flex items-center justify-between w-full gap-4">
        <span class="font-semibold">{{ count($selectedUsers) }} {{ str('user')->plural(count($selectedUsers)) }} selected</span>

        <div class="flex flex-wrap items-center gap-2">
            {{-- Assign Role --}}
            <div class="w-44 [&_select]:!text-base-content [&_select]:dark:!bg-base-300">
                <x-select
                    placeholder="Select Role" aria-label="Role to assign to selected users"
                    wire:model="bulk_role_id"
                    :options="$this->roles"
                    option-value="id"
                    option-label="name"
                />
            </div>
            <x-button
                label="Assign Role"
                icon="phosphor-users-three"
                class="btn-sm btn-primary border-white/70"
                wire:click="bulkAssignRole"
                :disabled="!$bulk_role_id"
                spinner="bulkAssignRole"
            />

            {{-- Lock --}}
            <x-button
                label="Lock"
                icon="phosphor-lock"
                class="btn-sm btn-warning"
                wire:click="bulkLockAccounts"
                spinner="bulkLockAccounts"
            />

            {{-- Unlock --}}
            <x-button
                label="Unlock"
                icon="phosphor-lock-open"
                class="btn-sm btn-success"
                wire:click="bulkUnlockAccounts"
                spinner="bulkUnlockAccounts"
            />

            {{-- Delete --}}
            <x-button
                label="Delete"
                icon="phosphor-trash"
                class="btn-sm btn-error"
                wire:click="bulkDeleteUsers"
                spinner="bulkDeleteUsers"
            />

            {{-- Cancel --}}
            <x-button
                label="Clear Selection"
                class="btn-sm btn-ghost"
                wire:click="$set('selectedUsers', [])"
            />
        </div>
    </div>
</x-alert>
