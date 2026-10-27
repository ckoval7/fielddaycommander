{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Update Notes Modal --}}
<x-modal wire:model="showNotesModal" title="Update Delivery Notes" class="backdrop-blur">
    <x-form wire:submit="updateNotes" class="space-y-4">
        <x-textarea
            label="Delivery Notes (optional)"
            wire:model="tempNotes"
            placeholder="Add or update delivery notes..."
            hint="Maximum 500 characters"
            rows="4"
        />

        <x-slot:actions class="flex-wrap max-sm:w-full">
            <x-button label="Cancel" wire:click="$set('showNotesModal', false)" class="btn-ghost" />
            <x-button
                label="Save Delivery Notes"
                type="submit"
                class="btn-primary"
                spinner="updateNotes"
            />
        </x-slot:actions>
    </x-form>
</x-modal>
