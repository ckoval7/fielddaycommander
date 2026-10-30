{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-modal wire:model="showLockModal" title="Lock Account">
    <p class="mb-4">Lock this account? The user won’t be able to sign in until the account is unlocked.</p>

    <x-slot:actions class="flex-wrap max-sm:w-full">
        <x-button label="Cancel" wire:click="$set('showLockModal', false)" class="btn-ghost" />
        <x-button label="Lock Account" wire:click="lockAccount" class="btn-warning" icon="phosphor-lock" spinner="lockAccount" />
    </x-slot:actions>
</x-modal>
