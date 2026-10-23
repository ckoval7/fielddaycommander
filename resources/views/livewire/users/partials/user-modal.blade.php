{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-modal wire:model="showModal" title="{{ $editingUserId ? 'Edit User' : 'Create User' }}" class="modal-lg">
    <form wire:submit="saveUser">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Call Sign --}}
            <x-input
                label="Callsign"
                wire:model="call_sign"
                autocomplete="off"
                autocapitalize="characters"
                autocorrect="off"
                spellcheck="false"
                icon="phosphor-megaphone"
                required
            />

            {{-- Email --}}
            <x-input
                label="Email"
                type="email"
                wire:model="email"
                autocomplete="off"
                icon="phosphor-envelope"
                required
            />

            {{-- First Name --}}
            <x-input
                label="First Name"
                wire:model="first_name"
                autocapitalize="words"
                autocomplete="off"
                icon="phosphor-user"
                required
            />

            {{-- Last Name --}}
            <x-input
                label="Last Name"
                wire:model="last_name"
                autocapitalize="words"
                autocomplete="off"
                icon="phosphor-user"
                required
            />

            {{-- License Class --}}
            <x-select
                label="License Class"
                wire:model="license_class"
                :options="[
                    ['value' => null, 'label' => 'Not Specified'],
                    ['value' => 'Technician', 'label' => 'Technician'],
                    ['value' => 'General', 'label' => 'General'],
                    ['value' => 'Advanced', 'label' => 'Advanced'],
                    ['value' => 'Extra', 'label' => 'Extra'],
                ]"
                option-value="value"
                option-label="label"
            />

            {{-- Role --}}
            <x-select
                label="Role"
                wire:model.live="role_id"
                :options="$this->roles"
                option-value="id"
                option-label="name"
                required
            />
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-1 mt-2">
            <label class="flex items-center gap-3 cursor-pointer py-2">
                <input type="checkbox" class="checkbox checkbox-sm" wire:model="is_youth" />
                <span class="text-sm">Youth (age 18 or younger)</span>
            </label>

            <label class="flex items-center gap-3 cursor-pointer py-2">
                <input type="checkbox" class="checkbox checkbox-sm" wire:model="is_cpr_aed_trained" />
                <span class="text-sm">CPR / AED trained</span>
            </label>
        </div>

        @if(!$editingUserId)
            {{-- Invitation Mode Toggle (only for new users) --}}
            @if(config('mail.email_configured'))
                <div class="flex flex-col mt-4">
                    <x-checkbox label="Send invitation email (expires in 72 hours)" wire:model.live="inviteMode" />
                </div>
            @endif

            {{-- Password Fields (only if not inviting) --}}
            @if(!$inviteMode)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <x-input
                        label="Password"
                        type="password"
                        wire:model="password"
                        autocomplete="new-password"
                        icon="phosphor-lock"
                        required
                    />

                    <x-input
                        label="Confirm Password"
                        type="password"
                        wire:model="password_confirmation"
                        autocomplete="new-password"
                        icon="phosphor-lock"
                        required
                    />
                </div>

                <div class="flex flex-col mt-2">
                    <label class="flex items-center gap-3 cursor-pointer py-2">
                        <input type="checkbox" class="checkbox checkbox-sm" wire:model="requirePasswordChange" />
                        <span class="text-sm">Require a password change at next sign-in</span>
                    </label>
                </div>
            @endif
        @endif

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showModal', false)" class="btn-ghost" />
            <x-button label="{{ $editingUserId ? 'Save Changes' : ($inviteMode ? 'Create and Send Invite' : 'Create User') }}" wire:click="saveUser" class="btn-primary" spinner="saveUser" />
        </x-slot:actions>
    </form>
</x-modal>
