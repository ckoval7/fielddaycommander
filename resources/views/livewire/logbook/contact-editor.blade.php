<div>
    <x-modal wire:model="showModal" title="Edit Contact" class="modal-lg">
        <form wire:submit.prevent>
            @if ($errors->any())
                <x-alert title="Please fix the following errors:" icon="phosphor-warning" class="alert-error mb-4">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                <x-input
                    label="Callsign"
                    wire:model="callsign"
                    placeholder="e.g. W1AW"
                    icon="phosphor-identification-card"
                    required
                />

                <x-input
                    label="Class"
                    wire:model="exchangeClass"
                    placeholder="e.g. 3A"
                    icon="phosphor-file-text"
                    hint="Transmitter count + class letter"
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

                <x-flatpickr
                    label="QSO Time"
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
                    placeholder="Optional notes..."
                    rows="2"
                />
            </div>

            <x-slot:actions>
                <x-button label="Cancel" wire:click="$set('showModal', false)" class="btn-ghost" />
                <x-button label="Save Changes" wire:click="save" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </form>
    </x-modal>

    {{-- Bulk Change Logger Modal --}}
    <x-modal wire:model="showBulkLoggerModal" title="Change Logger for {{ count($bulkLoggerContactIds) }} Contact(s)" class="backdrop-blur">
        <x-form wire:submit="bulkChangeLogger" class="space-y-4">
            <x-choices-offline
                label="New Logger"
                wire:model="bulkLoggerUserId"
                :options="$this->operators"
                option-label="display_name"
                option-value="id"
                placeholder="Select a logger..."
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

            <x-slot:actions>
                <x-button label="Cancel" wire:click="$set('showBulkLoggerModal', false)" class="btn-ghost" />
                <x-button label="Apply" type="submit" class="btn-primary" spinner="bulkChangeLogger" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
