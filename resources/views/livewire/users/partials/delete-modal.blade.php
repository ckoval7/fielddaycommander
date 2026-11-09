{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-modal wire:model="showDeleteModal" title="Delete User">
    <x-alert icon="phosphor-warning" class="alert-error">
        <strong class="block">Delete this user?</strong>
        This removes the account from the users list. The user won’t be able to sign in.
    </x-alert>

    <x-slot:actions class="flex-wrap max-sm:w-full">
        <x-button label="Cancel" wire:click="$set('showDeleteModal', false)" class="btn-ghost" />
        <x-button label="Delete User" wire:click="deleteUser" class="btn-error" icon="phosphor-trash" spinner="deleteUser" />
    </x-slot:actions>
</x-modal>
