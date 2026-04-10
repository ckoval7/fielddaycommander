{{-- Bulk Commit Modal --}}
<x-modal wire:model="showBulkCommitModal" title="Commit {{ count($selectedIds) }} Item(s) to Event" class="backdrop-blur">
    <x-form wire:submit="bulkCommitEquipment" class="space-y-4">
        <x-select
            label="Event"
            wire:model="bulkCommitEventId"
            icon="o-calendar"
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
            wire:model="bulkCommitExpectedDeliveryAt"
            mode="date"
            icon="o-calendar"
            hint="When do you expect to deliver this equipment?"
        />

        <x-textarea
            label="Delivery Notes"
            wire:model="bulkCommitDeliveryNotes"
            placeholder="Add any special instructions or notes about delivery..."
            hint="Maximum 500 characters"
            rows="4"
        />

        @error('bulkCommit')
            <div class="alert alert-error">
                <x-icon name="o-exclamation-triangle" class="w-5 h-5" />
                <span>{{ $message }}</span>
            </div>
        @enderror

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showBulkCommitModal', false)" class="btn-ghost" />
            <x-button label="Commit All" type="submit" class="btn-primary" spinner="bulkCommitEquipment" />
        </x-slot:actions>
    </x-form>
</x-modal>
