<x-modal wire:model="showResetModal" title="Reset Password">
    <form wire:submit="resetPassword">
        @if(config('mail.email_configured'))
            <div class="mb-4">
                <x-radio
                    wire:model.live="resetMethod"
                    :options="[
                        ['id' => 'manual', 'name' => 'Set new password manually'],
                        ['id' => 'email', 'name' => 'Send password reset email'],
                    ]"
                />
            </div>
        @endif

        @if($resetMethod === 'manual')
            <div class="space-y-4">
                <div>
                    <label for="generated-password" class="label label-text font-semibold">Generated Password</label>
                    <div class="flex gap-2">
                        <x-input
                            id="generated-password"
                            wire:model="newPassword"
                            icon="phosphor-lock"
                            class="font-mono grow"
                            readonly
                        />
                        <x-button
                            icon="phosphor-clipboard"
                            class="btn-ghost"
                            type="button"
                            x-on:click="
                                navigator.clipboard.writeText($wire.newPassword);
                                $wire.dispatch('toast', { title: 'Copied', description: 'Password copied to clipboard', icon: 'phosphor-clipboard', css: 'alert-info' });
                            "
                            tooltip="Copy to clipboard"
                        />
                    </div>
                </div>

                <x-alert icon="phosphor-info" class="alert-warning">
                    User will be required to change this password on next login.
                </x-alert>
            </div>
        @endif

        <div class="modal-action">
            <x-button label="Cancel" wire:click="$set('showResetModal', false)" class="btn-ghost" />
            <x-button
                label="{{ $resetMethod === 'email' ? 'Send Email' : 'Reset Password' }}"
                type="submit"
                class="btn-primary"
                icon="phosphor-key"
                spinner="resetPassword"
            />
        </div>
    </form>
</x-modal>
