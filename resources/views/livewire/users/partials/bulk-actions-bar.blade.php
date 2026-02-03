<x-alert icon="o-check-circle" class="alert-info mb-4">
    <div class="flex items-center justify-between w-full gap-4">
        <span class="font-semibold">{{ count($selectedUsers) }} user(s) selected</span>

        <div class="flex flex-wrap items-center gap-2">
            {{-- Assign Role --}}
            <div class="w-44 [&_select]:!text-base-content [&_select]:dark:!bg-base-300">
                <x-select
                    placeholder="Assign Role"
                    wire:model="bulk_role_id"
                    :options="$this->roles"
                    option-value="id"
                    option-label="name"
                />
            </div>
            <x-button
                label="Assign"
                icon="o-user-group"
                class="btn-sm btn-primary"
                wire:click="bulkAssignRole"
                :disabled="!$bulk_role_id"
                spinner="bulkAssignRole"
            />

            {{-- Lock --}}
            <x-button
                label="Lock"
                icon="o-lock-closed"
                class="btn-sm btn-warning"
                wire:click="bulkLockAccounts"
                spinner="bulkLockAccounts"
            />

            {{-- Unlock --}}
            <x-button
                label="Unlock"
                icon="o-lock-open"
                class="btn-sm btn-success"
                wire:click="bulkUnlockAccounts"
                spinner="bulkUnlockAccounts"
            />

            {{-- Delete --}}
            <x-button
                label="Delete"
                icon="o-trash"
                class="btn-sm btn-error"
                wire:click="bulkDeleteUsers"
                spinner="bulkDeleteUsers"
            />

            {{-- Cancel --}}
            <x-button
                label="Cancel"
                class="btn-sm btn-ghost"
                wire:click="$set('selectedUsers', [])"
            />
        </div>
    </div>
</x-alert>
