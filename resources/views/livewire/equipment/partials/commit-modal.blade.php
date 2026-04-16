{{-- Commit Equipment Modal --}}
<x-modal wire:model="showCommitModal" title="Commit Equipment to Event" class="backdrop-blur">
    <x-form wire:submit="commitEquipment" class="space-y-4">
        <x-select
            label="Event"
            wire:model="commitEventId"
            icon="phosphor-calendar"
            placeholder="Select an event..."
            :options="$this->upcomingEvents->map(fn($e) => [
                'value' => $e->id,
                'label' => $e->name . ' (' . $e->start_time->format('M j, Y') . ')'
            ])->toArray()"
            option-value="value"
            option-label="label"
        />

        <x-flatpickr
            label="Expected Delivery"
            wire:model="commitExpectedDeliveryAt"
            mode="date"
            icon="phosphor-calendar"
            hint="When do you expect to deliver this equipment?"
        />

        <x-textarea
            label="Delivery Notes"
            wire:model="commitDeliveryNotes"
            placeholder="Add any special instructions or notes about delivery..."
            hint="Maximum 500 characters"
            rows="4"
        />

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showCommitModal', false)" class="btn-ghost" />
            <x-button label="Commit Equipment" type="submit" class="btn-primary" spinner="commitEquipment" />
        </x-slot:actions>
    </x-form>
</x-modal>
