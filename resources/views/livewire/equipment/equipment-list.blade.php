<div>
    <x-slot:title>Equipment</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">My Equipment</h1>
            <div class="flex gap-2">
                <x-button label="Add Equipment" icon="o-plus" class="btn-primary" link="{{ route('equipment.create') }}" wire:navigate />
                @can('edit-any-equipment')
                    <x-button label="Add Club Equipment" icon="o-building-office" class="btn-secondary" link="{{ route('equipment.create', ['club' => true]) }}" wire:navigate />
                @endcan
            </div>
        </div>

        {{-- Search and Filters --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <x-input
                label="Search"
                placeholder="Search by make, model, or serial number..."
                wire:model.live.debounce.300ms="search"
                icon="o-magnifying-glass"
                clearable
            />

            <x-select
                label="Type"
                wire:model.live="typeFilter"
                :options="[
                    ['value' => null, 'label' => 'All Types'],
                    ['value' => 'radio', 'label' => 'Radio'],
                    ['value' => 'antenna', 'label' => 'Antenna'],
                    ['value' => 'amplifier', 'label' => 'Amplifier'],
                    ['value' => 'computer', 'label' => 'Computer'],
                    ['value' => 'power_supply', 'label' => 'Power Supply'],
                    ['value' => 'accessory', 'label' => 'Accessory'],
                    ['value' => 'tool', 'label' => 'Tool'],
                    ['value' => 'furniture', 'label' => 'Furniture'],
                    ['value' => 'other', 'label' => 'Other'],
                ]"
                option-value="value"
                option-label="label"
            />

            <x-select
                label="Status"
                wire:model.live="statusFilter"
                :options="[
                    ['value' => null, 'label' => 'All Status'],
                    ['value' => 'available', 'label' => 'Available'],
                    ['value' => 'committed', 'label' => 'Committed'],
                ]"
                option-value="value"
                option-label="label"
            />

            @can('view-all-equipment')
                <x-select
                    label="Owner"
                    wire:model.live="ownerFilter"
                    :options="[
                        ['value' => 'my', 'label' => 'My Equipment'],
                        ['value' => 'club', 'label' => 'Club Equipment'],
                        ['value' => 'all', 'label' => 'All Equipment'],
                    ]"
                    option-value="value"
                    option-label="label"
                />
            @endcan
        </div>

        {{-- Equipment Card --}}
        <x-card shadow>
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Make/Model</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Serial Number</th>
                            <th>Tags</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($equipment as $item)
                            <tr wire:key="equipment-{{ $item->id }}">
                                <td>
                                    @if($item->photo_path)
                                        <img
                                            src="{{ asset('storage/' . $item->photo_path) }}"
                                            alt="Equipment photo"
                                            class="w-12 h-12 object-cover rounded cursor-pointer hover:opacity-80 transition-opacity"
                                            wire:click="viewPhoto('{{ $item->photo_path }}', '{{ $item->make }} {{ $item->model }}')"
                                        />
                                    @else
                                        <div class="w-12 h-12 bg-base-300 rounded flex items-center justify-center">
                                            <x-icon name="o-camera" class="w-6 h-6 text-base-content/50" />
                                        </div>
                                    @endif
                                </td>
                                <td class="font-semibold">
                                    <div>{{ $item->make }}</div>
                                    <div class="text-sm opacity-60">{{ $item->model }}</div>
                                    @if($item->is_club_equipment)
                                        <div class="mt-1">
                                            <span class="badge badge-secondary badge-xs">
                                                <x-icon name="o-building-office" class="w-3 h-3 mr-0.5" />
                                                Club Equipment
                                            </span>
                                        </div>
                                        @if($item->managed_by_user_id && $item->manager)
                                            <div class="text-xs opacity-70 mt-0.5">
                                                Managed by {{ $item->manager->full_name }}
                                            </div>
                                        @endif
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-primary badge-sm">
                                        {{ ucfirst(str_replace('_', ' ', $item->type)) }}
                                    </span>
                                </td>
                                <td class="max-w-xs">
                                    <p class="truncate text-sm">{{ $item->description ?? '-' }}</p>
                                </td>
                                <td>
                                    <code class="text-xs">{{ $item->serial_number ?? '-' }}</code>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @if($item->tags && is_array($item->tags))
                                            @foreach($item->tags as $tag)
                                                <span class="badge badge-outline badge-xs">{{ $tag }}</span>
                                            @endforeach
                                        @else
                                            <span class="text-xs opacity-50">-</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-right">
                                    <x-dropdown>
                                        <x-slot:trigger>
                                            <x-button icon="o-ellipsis-vertical" class="btn-sm btn-ghost" />
                                        </x-slot:trigger>

                                        <x-menu-item title="Edit" icon="o-pencil" link="{{ route('equipment.edit', $item) }}" wire:navigate />
                                        <x-menu-separator />
                                        <x-menu-item title="Delete" icon="o-trash" class="text-error" wire:click="deleteEquipment({{ $item->id }})" wire:confirm="Are you sure you want to delete this equipment?" />
                                    </x-dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-8 text-base-content/60">
                                    <x-icon name="o-wrench-screwdriver" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                                    <p>No equipment found</p>
                                    <x-button label="Create First Equipment" icon="o-plus" class="btn-primary btn-sm mt-2" link="{{ route('equipment.create') }}" wire:navigate />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($equipment->hasPages())
                <div class="p-4">
                    {{ $equipment->links() }}
                </div>
            @endif
        </x-card>
    </div>

    {{-- Photo Viewer Modal --}}
    <x-modal wire:model="showPhotoModal" title="{{ $photoDescription ?? 'Equipment Photo' }}" class="backdrop-blur" box-class="max-w-4xl">
        @if($photoPath)
            <div class="flex justify-center items-center">
                <img
                    src="{{ asset('storage/' . $photoPath) }}"
                    alt="{{ $photoDescription ?? 'Equipment photo' }}"
                    class="max-w-full max-h-[70vh] object-contain rounded"
                />
            </div>
        @endif

        <x-slot:actions>
            <x-button label="Close" @click="$wire.showPhotoModal = false" class="btn-ghost" />
        </x-slot:actions>
    </x-modal>
</div>
