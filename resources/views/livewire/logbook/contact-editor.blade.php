<div>
    <x-modal wire:model="showModal" title="Edit Contact" class="modal-lg">
        <form wire:submit="save">
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
                <x-input
                    label="Callsign"
                    wire:model="callsign"
                    placeholder="e.g. W1AW"
                    icon="o-identification"
                    required
                />

                <x-input
                    label="Class"
                    wire:model="exchange_class"
                    placeholder="e.g. 3A"
                    icon="o-document-text"
                    hint="Transmitter count + class letter"
                    required
                />

                <x-select
                    label="Band"
                    wire:model="band_id"
                    :options="$this->bands"
                    option-label="name"
                    option-value="id"
                    placeholder="Select band"
                    icon="o-signal"
                    required
                />

                <x-select
                    label="Mode"
                    wire:model="mode_id"
                    :options="$this->modes"
                    option-label="name"
                    option-value="id"
                    placeholder="Select mode"
                    icon="o-radio"
                    required
                />

                <x-select
                    label="Section"
                    wire:model="section_id"
                    :options="$this->sections"
                    option-label="display_name"
                    option-value="id"
                    placeholder="Select section"
                    icon="o-map"
                    searchable
                    required
                />

                <x-flatpickr
                    label="QSO Time"
                    wire:model="qso_time"
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
                <x-button label="Save Changes" type="submit" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </form>
    </x-modal>
</div>
