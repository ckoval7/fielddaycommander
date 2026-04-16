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
                <span class="badge badge-club badge-lg">
                    <x-icon name="phosphor-buildings" class="w-4 h-4 mr-1" />
                    Club Equipment
                </span>
            @endif
            <x-button
                label="Cancel"
                icon="phosphor-x"
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
                    icon="phosphor-tag"
                    placeholder="e.g., Yaesu"
                    required
                />

                {{-- Model --}}
                <x-input
                    label="Model"
                    wire:model="model"
                    icon="phosphor-cube"
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
                    icon="phosphor-stack"
                />

                {{-- Serial Number --}}
                <x-input
                    label="Serial Number"
                    wire:model="serial_number"
                    icon="phosphor-hash"
                    placeholder="Optional"
                />
            </div>

            {{-- Owner (On-behalf-of Creation, Non-Club Only) --}}
            @if(!$isClubEquipment && !$equipmentId && auth()->user()->can('edit-any-equipment'))
                <div class="mt-4">
                    <x-select
                        label="Owner"
                        wire:model="owner_user_id"
                        :options="$this->availableOwners"
                        option-value="id"
                        option-label="name"
                        placeholder="Select equipment owner"
                        hint="Choose who this equipment belongs to"
                        icon="phosphor-user"
                        required
                    />
                </div>
            @endif

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
                        icon="phosphor-user"
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
                    icon="phosphor-lightning"
                    placeholder="Optional"
                />

                {{-- Value in USD --}}
                <x-input
                    label="Value (USD)"
                    wire:model="value_usd"
                    type="number"
                    step="0.01"
                    min="0"
                    icon="phosphor-currency-dollar"
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
                    icon="phosphor-phone"
                    placeholder="Optional"
                />

                {{-- Tags --}}
                <x-input
                    label="Tags"
                    wire:model.live="tagsInput"
                    icon="phosphor-tag"
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
                {{-- Current Photo (when editing with existing photo) --}}
                @if($existingPhotoPath && !$photo)
                    <div class="space-y-2">
                        <p class="text-sm text-base-content/70">Current Photo:</p>
                        <img
                            src="{{ asset('storage/' . $existingPhotoPath) }}"
                            alt="{{ $make }} {{ $model }}"
                            class="h-40 rounded-lg border border-base-300"
                        />
                    </div>
                @endif

                {{-- Photo Upload --}}
                <div>
                    <label for="photo-input" class="block text-sm font-medium mb-2">
                        {{ $photo ? 'New Photo' : ($existingPhotoPath ? 'Replace Photo' : 'Upload Photo') }}
                    </label>

                    <input
                        type="file"
                        wire:model="photo"
                        accept="image/png,image/jpeg,image/jpg"
                        class="hidden"
                        id="photo-input"
                        x-ref="photoInput"
                    >

                    @if($photo)
                        {{-- Preview for newly uploaded photo --}}
                        <div class="space-y-3">
                            @if(in_array($photo->getMimeType(), ['image/jpeg', 'image/png', 'image/jpg']))
                                <img
                                    src="{{ $photo->temporaryUrl() }}"
                                    alt="{{ $make }} {{ $model }} preview"
                                    class="h-40 rounded-lg border border-base-300"
                                />
                            @endif
                            <div class="flex items-center gap-2 text-sm">
                                <x-icon name="phosphor-check-circle" class="w-5 h-5 text-success" />
                                <span class="text-base-content/70">{{ $photo->getClientOriginalName() }}</span>
                            </div>
                            <x-button
                                label="Choose Different Photo"
                                icon="phosphor-arrow-clockwise"
                                class="btn-sm"
                                x-on:click="$refs.photoInput.click()"
                            />
                        </div>
                    @else
                        {{-- Upload placeholder --}}
                        <div
                            class="flex flex-col items-center justify-center py-12 px-6 border-2 border-dashed border-base-300 rounded-lg bg-base-200/30 hover:bg-base-200/50 hover:border-primary/50 transition-colors cursor-pointer"
                            x-on:click="$refs.photoInput.click()"
                        >
                            <div wire:loading.remove wire:target="photo">
                                <x-icon name="phosphor-image" class="w-12 h-12 text-base-content/40 mb-3" />
                                <p class="text-base font-medium text-base-content mb-1">Click to upload photo</p>
                                <p class="text-sm text-base-content/60">PNG or JPEG up to 5MB</p>
                            </div>
                            <div wire:loading wire:target="photo" class="flex items-center gap-2">
                                <span class="loading loading-spinner loading-md"></span>
                                <span class="text-base-content/70">Uploading...</span>
                            </div>
                        </div>
                    @endif

                    @error('photo')
                        <p class="mt-2 text-sm text-error">{{ $message }}</p>
                    @enderror

                    <p class="mt-2 text-xs text-base-content/50">Max 5MB. Formats: PNG, JPEG</p>
                </div>
            </div>
        </x-card>

        {{-- Form Actions --}}
        <div class="flex gap-3">
            <x-button
                label="Cancel"
                icon="phosphor-x"
                class="btn-ghost"
                link="{{ route('equipment.index') }}"
                wire:navigate
            />
            <x-button
                label="{{ $equipmentId ? 'Update Equipment' : 'Create Equipment' }}"
                type="submit"
                class="btn-primary"
                icon="phosphor-check"
                spinner="save"
            />
        </div>
    </form>
</div>
