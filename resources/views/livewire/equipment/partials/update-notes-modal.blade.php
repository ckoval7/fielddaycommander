{{-- Update Notes Modal --}}
<x-modal wire:model="showNotesModal" title="Update Delivery Notes" class="backdrop-blur">
    <x-form wire:submit="updateNotes" class="space-y-4">
        <x-textarea
            label="Delivery Notes"
            wire:model="tempNotes"
            placeholder="Add or update delivery notes..."
            hint="Maximum 500 characters"
            rows="4"
        />

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showNotesModal', false)" class="btn-ghost" />
            <x-button
                label="Update Notes"
                type="submit"
                class="btn-primary"
                spinner="updateNotes"
                wire:click="updateNotes({{ $updateNoteId }}, $wire.tempNotes)"
            />
        </x-slot:actions>
    </x-form>
</x-modal>
