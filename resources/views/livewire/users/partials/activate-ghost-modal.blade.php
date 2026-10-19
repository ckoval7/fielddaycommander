{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-modal wire:model="showActivateModal" title="Activate Account">
    <form wire:submit="activateGhost">
        <div class="space-y-4">
            <p class="text-sm">
                This account was created from a callsign and has no login. Give it a real email and a temporary password so the operator can sign in.
            </p>

            <x-input label="Email" type="email" wire:model="activateEmail" autocomplete="off" icon="phosphor-envelope" required />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input label="First name" wire:model="activateFirstName" autocomplete="off" required autocapitalize="words" />
                <x-input label="Last name" wire:model="activateLastName" autocomplete="off" required autocapitalize="words" />
            </div>

            <div>
                <label for="activate-password" class="block py-1 text-sm font-semibold">Temporary Password</label>
                <div class="flex gap-2">
                    <x-input
                        id="activate-password"
                        wire:model="activatePassword"
                        icon="phosphor-lock"
                        class="font-mono grow"
                        readonly
                    />
                    <x-button
                        icon="phosphor-clipboard"
                        class="btn-ghost"
                        type="button"
                        x-on:click="
                            navigator.clipboard.writeText($wire.activatePassword);
                            $wire.dispatch('toast', { title: 'Copied', description: 'Password copied to clipboard', icon: 'phosphor-clipboard', css: 'alert-info' });
                        "
                        tooltip="Copy to clipboard"
                    />
                </div>
            </div>

            <x-alert icon="phosphor-info" class="alert-info">
                The account is unlocked, given the Operator role if it has none, and must change this password on first login.
            </x-alert>
        </div>

        <div class="modal-action">
            <x-button label="Cancel" wire:click="$set('showActivateModal', false)" class="btn-ghost" />
            <x-button label="Activate" type="submit" class="btn-primary" icon="phosphor-user-check" spinner="activateGhost" />
        </div>
    </form>
</x-modal>
