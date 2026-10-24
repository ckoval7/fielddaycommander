{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-modal wire:model="showActivateModal" title="Activate Account">
    <form wire:submit="activateGhost">
        <div class="space-y-4">
            <p class="text-sm">
                This is a callsign record without a personal sign-in. Enter the operator’s email and name, then share the temporary password with them.
            </p>

            <x-input label="Email" type="email" wire:model="activateEmail" autocomplete="off" icon="phosphor-envelope" required />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input label="First Name" wire:model="activateFirstName" autocomplete="off" required autocapitalize="words" />
                <x-input label="Last Name" wire:model="activateLastName" autocomplete="off" required autocapitalize="words" />
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
                            if (await $copy($wire.activatePassword)) {
                                $wire.dispatch('toast', { title: 'Copied', description: 'Password copied to clipboard', icon: 'phosphor-clipboard', css: 'alert-info' });
                            } else {
                                $wire.dispatch('toast', { title: 'Could not copy', description: 'Select the password and copy it manually', icon: 'phosphor-warning', css: 'alert-warning' });
                            }
                        "
                        tooltip="Copy password"
                        aria-label="Copy password"
                    />
                </div>
            </div>

            <x-alert icon="phosphor-info" class="alert-info">
                Activating removes the account lock and assigns the Operator role if no role is assigned. The operator must change the password at next sign-in.
            </x-alert>
        </div>

        <div class="modal-action">
            <x-button label="Cancel" wire:click="$set('showActivateModal', false)" class="btn-ghost" />
            <x-button label="Activate Account" type="submit" class="btn-primary" icon="phosphor-user-check" spinner="activateGhost" />
        </div>
    </form>
</x-modal>
