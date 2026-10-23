{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-modal wire:model="showDeleteModal" title="Delete User">
    <x-alert icon="phosphor-warning" title="Delete this user?" class="alert-error">
        This removes the account from the users list. The user won’t be able to sign in.
    </x-alert>

    <x-slot:actions>
        <x-button label="Cancel" wire:click="$set('showDeleteModal', false)" class="btn-ghost" />
        <x-button label="Delete User" wire:click="deleteUser" class="btn-error" icon="phosphor-trash" spinner="deleteUser" />
    </x-slot:actions>
</x-modal>
