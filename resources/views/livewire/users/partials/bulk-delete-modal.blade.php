{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-modal wire:model="showBulkDeleteModal" title="Delete Users">
    <x-alert icon="phosphor-warning" title="Delete {{ count($selectedUsers) }} {{ str('user')->plural(count($selectedUsers)) }}?" class="alert-error">
        This removes the selected accounts from the users list. These users won’t be able to sign in.
    </x-alert>

    <x-slot:actions class="flex-wrap max-sm:w-full">
        <x-button label="Cancel" wire:click="$set('showBulkDeleteModal', false)" class="btn-ghost" />
        <x-button label="Delete Users" wire:click="bulkDeleteUsers" class="btn-error" icon="phosphor-trash" spinner="bulkDeleteUsers" />
    </x-slot:actions>
</x-modal>
