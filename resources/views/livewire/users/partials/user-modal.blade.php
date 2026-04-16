<x-modal wire:model="showModal" title="{{ $editingUserId ? 'Edit User' : 'Create User' }}" class="modal-lg">
    <form wire:submit="saveUser">
        {{-- Validation Error Summary --}}
        @if ($errors->any())
            <x-alert title="Please fix the following errors:" icon="o-exclamation-triangle" class="alert-error mb-4">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Call Sign --}}
            <x-input
                label="Call Sign"
                wire:model="call_sign"
                icon="o-megaphone"
                required
            />

            {{-- Email --}}
            <x-input
                label="Email"
                type="email"
                wire:model="email"
                icon="o-envelope"
                required
            />

            {{-- First Name --}}
            <x-input
                label="First Name"
                wire:model="first_name"
                icon="o-user"
                required
            />

            {{-- Last Name --}}
            <x-input
                label="Last Name"
                wire:model="last_name"
                icon="o-user"
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
            <label class="label cursor-pointer justify-start gap-3">
                <input type="checkbox" class="checkbox checkbox-sm" wire:model="is_youth" />
                <span class="label-text">Youth (age 18 or younger)</span>
            </label>

            <label class="label cursor-pointer justify-start gap-3">
                <input type="checkbox" class="checkbox checkbox-sm" wire:model="is_cpr_aed_trained" />
                <span class="label-text">CPR / AED trained</span>
            </label>
        </div>

        @if(!$editingUserId)
            {{-- Invitation Mode Toggle (only for new users) --}}
            @if(config('mail.email_configured'))
                <div class="form-control mt-4">
                    <x-checkbox label="Send invitation email (user sets own password)" wire:model.live="inviteMode" />
                </div>
            @endif

            {{-- Password Fields (only if not inviting) --}}
            @if(!$inviteMode)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <x-input
                        label="Password"
                        type="password"
                        wire:model="password"
                        icon="o-lock-closed"
                        required
                    />

                    <x-input
                        label="Confirm Password"
                        type="password"
                        wire:model="password_confirmation"
                        icon="o-lock-closed"
                        required
                    />
                </div>

                <div class="form-control mt-2">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" class="checkbox checkbox-sm" wire:model="requirePasswordChange" />
                        <span class="label-text">User must change password on next login</span>
                    </label>
                </div>
            @endif
        @endif

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showModal', false)" class="btn-ghost" />
            <x-button label="{{ $editingUserId ? 'Update' : 'Create' }}" wire:click="saveUser" class="btn-primary" spinner="saveUser" />
        </x-slot:actions>
    </form>
</x-modal>
