<div>
    <x-modal wire:model="showModal" title="Edit Contact" class="modal-lg">
        <form wire:submit.prevent>
            @if ($errors->any())
                <x-alert title="Please fix the following errors:" icon="o-exclamation-triangle" class="alert-error mb-4">
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
                    icon="o-identification"
                    required
                />

                <x-input
                    label="Class"
                    wire:model="exchangeClass"
                    placeholder="e.g. 3A"
                    icon="o-document-text"
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
                    icon="o-map"
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
                    icon="o-signal"
                    required
                />

                <x-select
                    label="Mode"
                    wire:model="modeId"
                    :options="$this->modes"
                    option-label="name"
                    option-value="id"
                    placeholder="Select mode"
                    icon="o-radio"
                    required
                />

                <x-flatpickr
                    label="QSO Time"
                    wire:model="qsoTime"
                    icon="o-clock"
                    required
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
</div>
