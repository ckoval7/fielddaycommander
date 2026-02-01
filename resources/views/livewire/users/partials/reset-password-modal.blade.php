<x-modal wire:model="showResetModal" title="Reset Password">
    <form wire:submit="resetPassword">
        <div class="form-control mb-4">
            <x-radio label="Send password reset email" value="1" wire:model.live="sendResetEmail" />
            <x-radio label="Set new password manually" value="0" wire:model.live="sendResetEmail" />
        </div>

        @if(!$sendResetEmail)
            <div class="space-y-4">
                <x-input
                    label="New Password"
                    type="password"
                    wire:model="resetPassword"
                    icon="o-lock-closed"
                    required
                />

                <x-input
                    label="Confirm New Password"
                    type="password"
                    wire:model="resetPassword_confirmation"
                    icon="o-lock-closed"
                    required
                />

                <div class="alert alert-warning">
                    <x-icon name="o-information-circle" class="w-5 h-5" />
                    <span>User will be required to change this password on next login.</span>
                </div>
            </div>
        @endif

        <x-slot:actions>
            <x-button label="Cancel" wire:click="$set('showResetModal', false)" />
            <x-button
                label="{{ $sendResetEmail ? 'Send Email' : 'Reset Password' }}"
                type="submit"
                class="btn-primary"
                icon="o-key"
                spinner="resetPassword"
            />
        </x-slot:actions>
    </form>
</x-modal>
