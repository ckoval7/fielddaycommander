{{-- App-wide floating validation summary, driven by resources/js/components/form-error-summary.js. Rendered once per layout. --}}
<div
    x-data="formErrorSummaryPanel"
    popover="manual"
    role="alert"
    aria-live="assertive"
    class="fixed inset-auto bottom-4 inset-x-4 m-0 p-0 border-0 bg-transparent overflow-visible sm:left-auto sm:right-4 sm:w-96"
>
    <div class="alert alert-error shadow-lg items-start">
        <x-icon name="phosphor-warning" class="w-5 h-5 shrink-0 mt-0.5" />
        <div class="flex-1 min-w-0">
            <div class="font-bold">Please fix the following errors:</div>
            <ul class="text-sm mt-1 max-h-48 overflow-y-auto space-y-0.5">
                <template x-for="error in $store.formErrors.messages" :key="error.id">
                    {{-- Bullet is drawn manually: a <button> is always laid out inline-block, which pushes a native list marker down to its last line. --}}
                    <li class="flex items-start gap-2">
                        <span aria-hidden="true">&bull;</span>
                        <button type="button" class="text-left cursor-pointer hover:underline" x-on:click="$store.formErrors.focusField(error.field)" x-text="error.message"></button>
                    </li>
                </template>
            </ul>
        </div>
        <button type="button" class="btn btn-ghost btn-xs btn-circle" x-on:click="$store.formErrors.dismiss()" aria-label="Dismiss errors">
            <x-icon name="phosphor-x" class="w-4 h-4" />
        </button>
    </div>
</div>
