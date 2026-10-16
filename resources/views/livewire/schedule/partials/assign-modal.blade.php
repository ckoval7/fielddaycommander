{{-- Assignment Modal --}}
<x-modal wire:model="showAssignModal" title="Assign User to Shift" class="!overflow-visible" box-class="!overflow-visible">
    <div>
        <div class="space-y-4">
            <x-choices-offline
                label="User"
                wire:model="assignUserId"
                :options="$this->users"
                option-value="id"
                option-label="name"
                placeholder="Search for a user..."
                searchable
                single
            />
        </div>

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.showAssignModal = false" />
            <x-button label="Assign" wire:click="assignUser" class="btn-primary" spinner="assignUser" />
        </x-slot:actions>
    </div>
</x-modal>
