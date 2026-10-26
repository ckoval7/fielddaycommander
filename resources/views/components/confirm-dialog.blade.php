{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- App-wide confirmation modal for wire:confirm and $confirm(), driven by resources/js/components/confirm-dialog.js. Rendered once per layout. --}}
<dialog
    x-data="confirmDialogPanel"
    x-on:close="$store.confirmDialog.settle(false)"
    aria-labelledby="confirm-dialog-message"
    class="modal modal-bottom sm:modal-middle"
    data-confirm-dialog
>
    <div class="modal-box sm:max-w-md max-h-[90dvh] overflow-y-auto overscroll-contain pb-[max(1.5rem,env(safe-area-inset-bottom))]">
        <p id="confirm-dialog-message" class="text-base whitespace-pre-line break-words" x-text="$store.confirmDialog.message"></p>

        <template x-if="$store.confirmDialog.expected !== null">
            <label class="block mt-4">
                <span class="text-sm">Type <strong x-text="$store.confirmDialog.expected"></strong> to confirm</span>
                <input
                    type="text"
                    x-ref="typed"
                    class="input w-full mt-1"
                    autocomplete="off"
                    autocapitalize="off"
                    autocorrect="off"
                    spellcheck="false"
                    x-model="$store.confirmDialog.typed"
                    x-on:keydown.enter.prevent="$store.confirmDialog.settle(true)"
                />
            </label>
        </template>

        <div class="modal-action flex-wrap">
            <button type="button" x-ref="cancel" class="btn btn-ghost max-sm:flex-1" x-on:click="$store.confirmDialog.settle(false)">Cancel</button>
            <button
                type="button"
                x-ref="confirm"
                class="btn max-sm:flex-1"
                :class="$store.confirmDialog.danger ? 'btn-error' : 'btn-primary'"
                :disabled="!$store.confirmDialog.canConfirm"
                x-on:click="$store.confirmDialog.settle(true)"
                x-text="$store.confirmDialog.confirmLabel"
            ></button>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop">
        <button type="submit" aria-label="Cancel">Close</button>
    </form>
</dialog>
