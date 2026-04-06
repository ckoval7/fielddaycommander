<x-modal wire:model="showLockModal" title="Lock User Account">
    <p class="mb-4">Lock this user account? The user will not be able to log in.</p>

    <x-slot:actions>
        <x-button label="Cancel" wire:click="$set('showLockModal', false)" class="btn-ghost" />
        <x-button label="Lock Account" wire:click="lockAccount" class="btn-warning" icon="o-lock-closed" spinner="lockAccount" />
    </x-slot:actions>
</x-modal>
