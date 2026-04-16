<x-modal wire:model="showDeleteModal" title="Delete User">
    <x-alert icon="phosphor-warning" title="Are you sure?" class="alert-error">
        This action will delete the user account. This can be undone by restoring from the deleted users list.
    </x-alert>

    <x-slot:actions>
        <x-button label="Cancel" wire:click="$set('showDeleteModal', false)" class="btn-ghost" />
        <x-button label="Delete User" wire:click="deleteUser" class="btn-error" icon="phosphor-trash" spinner="deleteUser" />
    </x-slot:actions>
</x-modal>
