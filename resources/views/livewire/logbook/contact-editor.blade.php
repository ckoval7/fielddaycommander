{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-modal wire:model="showModal" title="Edit Contact" class="modal-lg">
        {{-- Rendered only while open: its pickers are heavy and most visits never edit. --}}
        @if($showModal)
            <form wire:submit.prevent>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                    <x-input
                        label="Callsign"
                        wire:model="callsign"
                        autocomplete="off"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        placeholder="e.g. W1AW"
                        icon="phosphor-identification-card"
                        required
                    />

                    <x-input
                        label="Class"
                        wire:model="exchangeClass"
                        autocomplete="off"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        placeholder="e.g. 3A"
                        icon="phosphor-file-text"
                        hint="Transmitter count and class letter"
                        required
                    />

                    <x-choices-offline
                        label="Section"
                        wire:model="sectionId"
                        :options="$this->sections"
                        option-label="display_name"
                        option-value="id"
                        placeholder="Select section"
                        icon="phosphor-map-trifold"
                        searchable
                        single
                        required
                    />

                    <x-select
                        label="Band"
                        wire:model="bandId"
                        :options="$this->bands"
                        option-label="name"
                        option-value="id"
                        placeholder="Select band"
                        icon="phosphor-cell-signal-high"
                        required
                    />

                    <x-select
                        label="Mode"
                        wire:model="modeId"
                        :options="$this->modes"
                        option-label="name"
                        option-value="id"
                        placeholder="Select mode"
                        icon="phosphor-radio"
                        required
                    />

                    <x-input
                        label="Sub-mode (optional)"
                        wire:model="submode"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        list="contact-editor-submodes"
                        maxlength="20"
                        placeholder="e.g. FT8, SSTV, USB"
                        class="uppercase"
                    />
                    <datalist id="contact-editor-submodes">
                        @foreach(array_merge(\App\Services\ModeResolverService::submodeSuggestions('Digital'), \App\Services\ModeResolverService::submodeSuggestions('Phone')) as $suggestion)
                            <option value="{{ $suggestion }}"></option>
                        @endforeach
                    </datalist>

                    <x-flatpickr
                        label="QSO Time (UTC)"
                        wire:model="qsoTime"
                        icon="phosphor-clock"
                        required
                        now-button
                    />
                </div>

                <div class="mt-4">
                    <x-textarea
                        label="Notes"
                        wire:model="notes"
                        placeholder="Optional notes"
                        rows="2"
                    />
                </div>

                <x-slot:actions class="flex-wrap max-sm:w-full">
                    <x-button label="Cancel" wire:click="$set('showModal', false)" class="btn-ghost" />
                    <x-button label="Save Changes" wire:click="save" class="btn-primary" spinner="save" />
                </x-slot:actions>
            </form>
        @endif
    </x-modal>

    {{-- Bulk Change Logger Modal --}}
    <x-modal wire:model="showBulkLoggerModal" title="Change Logger for {{ count($bulkLoggerContactIds) }} {{ str('Contact')->plural(count($bulkLoggerContactIds)) }}" class="backdrop-blur">
        @if($showBulkLoggerModal)
            <x-form wire:submit="bulkChangeLogger" class="space-y-4">
                <x-choices-offline
                    label="New Logger"
                    wire:model="bulkLoggerUserId"
                    :options="$this->operators"
                    option-label="display_name"
                    option-value="id"
                    placeholder="Select a logger"
                    icon="phosphor-user"
                    searchable
                    single
                    required
                />

                @error('bulkLoggerUserId')
                    <div class="alert alert-error">
                        <x-icon name="phosphor-warning" class="w-5 h-5" />
                        <span>{{ $message }}</span>
                    </div>
                @enderror

                <x-slot:actions class="flex-wrap max-sm:w-full">
                    <x-button label="Cancel" wire:click="$set('showBulkLoggerModal', false)" class="btn-ghost" />
                    <x-button label="Change Logger" type="submit" class="btn-primary" spinner="bulkChangeLogger" />
                </x-slot:actions>
            </x-form>
        @endif
    </x-modal>
</div>
