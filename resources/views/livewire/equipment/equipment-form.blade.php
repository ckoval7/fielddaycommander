<div class="space-y-6">
    {{-- Header --}}
    <x-header
        title="{{ $equipmentId ? 'Edit Equipment' : ($isClubEquipment ? 'Create Club Equipment' : 'Create Equipment') }}"
        subtitle="{{ $equipmentId ? 'Update equipment details' : ($isClubEquipment ? 'Add equipment to the club inventory' : 'Add a new piece of equipment to your inventory') }}"
        separator
        progress-indicator
    >
        <x-slot:actions>
            @if($isClubEquipment)
                <span class="badge badge-secondary badge-lg">
                    <x-icon name="o-building-office" class="w-4 h-4 mr-1" />
                    Club Equipment
                </span>
            @endif
            <x-button
                label="Cancel"
                icon="o-x-mark"
                class="btn-ghost"
                link="{{ route('equipment.index') }}"
                wire:navigate
            />
        </x-slot:actions>
    </x-header>

    <form wire:submit="save">
        {{-- Equipment Details Card --}}
        <x-card class="mb-6">
            <x-slot:title>Equipment Details</x-slot:title>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Make --}}
                <x-input
                    label="Make"
                    wire:model="make"
                    icon="o-tag"
                    placeholder="e.g., Yaesu"
                    required
                />

                {{-- Model --}}
                <x-input
                    label="Model"
                    wire:model="model"
                    icon="o-cube"
                    placeholder="e.g., FT-891"
                    required
                />

                {{-- Type --}}
                <x-select
                    label="Type"
                    wire:model="type"
                    :options="$this->equipmentTypes"
                    option-value="value"
                    option-label="label"
                    required
                    icon="o-catalog"
                />

                {{-- Serial Number --}}
                <x-input
                    label="Serial Number"
                    wire:model="serial_number"
                    icon="o-hashtag"
                    placeholder="Optional"
                />
            </div>

            {{-- Description --}}
            <div class="mt-4">
                <x-textarea
                    label="Description"
                    wire:model="description"
                    placeholder="Detailed description of the equipment"
                    hint="Optional"
                    rows="3"
                />
            </div>

            {{-- Managed By (Club Equipment Only) --}}
            @if($isClubEquipment)
                <div class="mt-4">
                    <x-select
                        label="Managed By"
                        wire:model="managed_by_user_id"
                        :options="$this->availableManagers"
                        option-value="id"
                        option-label="name"
                        placeholder="No specific manager"
                        hint="Optional - Assign a specific person to manage this club equipment"
                        icon="o-user"
                    />
                </div>
            @endif
        </x-card>

        {{-- Technical Specifications Card --}}
        <x-card class="mb-6">
            <x-slot:title>Technical Specifications</x-slot:title>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Power Output Watts --}}
                <x-input
                    label="Power Output (Watts)"
                    wire:model="power_output_watts"
                    type="number"
                    min="1"
                    max="10000"
                    icon="o-bolt"
                    placeholder="Optional"
                />

                {{-- Value in USD --}}
                <x-input
                    label="Value (USD)"
                    wire:model="value_usd"
                    type="number"
                    step="0.01"
                    min="0"
                    icon="o-currency-dollar"
                    placeholder="$0.00"
                    hint="Optional"
                />
            </div>
        </x-card>

        {{-- Operational Information Card --}}
        <x-card class="mb-6">
            <x-slot:title>Operational Information</x-slot:title>

            <div class="space-y-4">
                {{-- Emergency Contact Phone --}}
                <x-input
                    label="Emergency Contact Phone"
                    wire:model="emergency_contact_phone"
                    type="tel"
                    icon="o-phone"
                    placeholder="Optional"
                />

                {{-- Tags --}}
                <x-input
                    label="Tags"
                    wire:model.live="tagsInput"
                    icon="o-tag"
                    placeholder="Comma-separated"
                    hint="Optional - e.g., HF,Portable,Contest"
                />

                {{-- Notes --}}
                <x-textarea
                    label="Notes"
                    wire:model="notes"
                    placeholder="Additional notes or maintenance history"
                    hint="Optional"
                    rows="3"
                />
            </div>
        </x-card>

        {{-- Supported Bands Card --}}
        <x-card class="mb-6">
            <x-slot:title>Supported Bands</x-slot:title>

            <p class="text-sm text-base-content/70 mb-4">Select all bands this equipment supports:</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                @foreach($this->bands as $band)
                    <x-checkbox
                        label="{{ $band->name }}"
                        wire:model="selectedBands"
                        value="{{ $band->id }}"
                    />
                @endforeach
            </div>
        </x-card>

        {{-- Photo Card --}}
        <x-card class="mb-6">
            <x-slot:title>Equipment Photo</x-slot:title>

            <div class="space-y-4">
                {{-- Photo Preview (existing) --}}
                @if($existingPhotoPath)
                    <div class="space-y-2">
                        <p class="text-sm text-base-content/70">Current Photo:</p>
                        <img
                            src="{{ asset('storage/' . $existingPhotoPath) }}"
                            alt="Equipment photo"
                            class="h-40 rounded-lg border border-base-300"
                        />
                    </div>
                @endif

                {{-- Photo Upload --}}
                <x-file
                    wire:model="photo"
                    label="Upload Photo"
                    accept="image/png, image/jpeg"
                    hint="Max 5MB. Formats: PNG, JPEG"
                >
                    @if($photo)
                        <img
                            src="{{ $photo->temporaryUrl() }}"
                            alt="New equipment photo"
                            class="h-40 rounded-lg border border-base-300"
                        />
                    @elseif($existingPhotoPath)
                        <img
                            src="{{ asset('storage/' . $existingPhotoPath) }}"
                            alt="Equipment photo"
                            class="h-40 rounded-lg border border-base-300"
                        />
                    @else
                        <div class="text-center py-8">
                            <p class="text-base-content/70">Click to upload photo</p>
                        </div>
                    @endif
                </x-file>
            </div>
        </x-card>

        {{-- Form Actions --}}
        <div class="flex gap-3">
            <x-button
                label="Cancel"
                icon="o-x-mark"
                class="btn-ghost"
                link="{{ route('equipment.index') }}"
                wire:navigate
            />
            <x-button
                label="{{ $equipmentId ? 'Update Equipment' : 'Create Equipment' }}"
                type="submit"
                class="btn-primary"
                icon="o-check"
                spinner="save"
            />
        </div>
    </form>
</div>
