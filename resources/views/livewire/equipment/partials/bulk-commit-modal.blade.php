{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Bulk Commit Modal --}}
<x-modal wire:model="showBulkCommitModal" title="Commit {{ count($selectedIds) }} {{ str('Item')->plural(count($selectedIds)) }} to Event" class="backdrop-blur">
    <x-form wire:submit="bulkCommitEquipment" class="space-y-4">
        <x-select
            label="Event"
            wire:model="bulkCommitEventId"
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
            label="Expected Delivery (optional)"
            wire:model="bulkCommitExpectedDeliveryAt"
            mode="date"
            icon="phosphor-calendar"
            hint="When do you expect to deliver this equipment?"
        />

        <x-textarea
            label="Delivery Notes (optional)"
            wire:model="bulkCommitDeliveryNotes"
            placeholder="Delivery instructions or pickup details"
            hint="Maximum 500 characters"
            rows="4"
        />

        @error('bulkCommit')
            <div class="alert alert-error">
                <x-icon name="phosphor-warning" class="w-5 h-5" />
                <span>{{ $message }}</span>
            </div>
        @enderror

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showBulkCommitModal', false)" class="btn-ghost" />
            <x-button label="Commit Selected" type="submit" class="btn-primary" spinner="bulkCommitEquipment" />
        </x-slot:actions>
    </x-form>
</x-modal>
