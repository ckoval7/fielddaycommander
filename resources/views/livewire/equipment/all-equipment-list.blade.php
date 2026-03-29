<div>
    <x-slot:title>All User Catalogs</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">All User Catalogs</h1>
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
                label="Owner"
                wire:model.live="userFilter"
                :options="$this->userOptions"
                option-value="value"
                option-label="label"
            />

            <x-select
                label="Type"
                wire:model.live="typeFilter"
                :options="array_merge(
                    [['value' => null, 'label' => 'All Types']],
                    \App\Models\Equipment::typeOptions()
                )"
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
        </div>

        {{-- Equipment Card --}}
        <x-card shadow>
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>Photo</th>
                            <th>Owner</th>
                            <th>Make/Model</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Serial Number</th>
                            <th>Status</th>
                            <th>Tags</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($equipment as $item)
                            <tr wire:key="equipment-{{ $item->id }}">
                                <td>
                                    @if($item->photo_path)
                                        <img
                                            src="{{ asset('storage/' . $item->photo_path) }}"
                                            alt="{{ $item->make }} {{ $item->model }}"
                                            class="w-12 h-12 object-cover rounded cursor-pointer hover:opacity-80 transition-opacity"
                                            wire:click="viewPhoto('{{ $item->photo_path }}', '{{ $item->make }} {{ $item->model }}')"
                                        />
                                    @else
                                        <div class="w-12 h-12 bg-base-300 rounded flex items-center justify-center">
                                            <x-icon name="o-camera" class="w-6 h-6 text-base-content/50" />
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if($item->is_club_equipment)
                                        <span class="badge badge-club badge-sm">
                                            <x-icon name="o-building-office" class="w-3 h-3 mr-0.5" />
                                            Club
                                        </span>
                                        @if($item->managed_by_user_id && $item->manager)
                                            <div class="text-xs opacity-70 mt-0.5">
                                                Mgr: {{ $item->manager->call_sign }}
                                            </div>
                                        @endif
                                    @elseif($item->owner)
                                        <div class="font-semibold text-sm">{{ $item->owner->call_sign }}</div>
                                        @if($item->owner->first_name || $item->owner->last_name)
                                            <div class="text-xs opacity-70">
                                                {{ trim($item->owner->first_name . ' ' . $item->owner->last_name) }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-xs opacity-50">—</span>
                                    @endif
                                </td>
                                <td class="font-semibold">
                                    <div>{{ $item->make }}</div>
                                    <div class="text-sm opacity-60">{{ $item->model }}</div>
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
                                    @php
                                        $statusClasses = match($item->status ?? 'available') {
                                            'committed' => 'badge-info',
                                            'delivered' => 'badge-success',
                                            'in_use' => 'badge-warning',
                                            'returned' => 'badge-neutral',
                                            'cancelled' => 'badge-error',
                                            'lost' => 'badge-error',
                                            'damaged' => 'badge-error',
                                            default => 'badge-success badge-outline'
                                        };
                                        $statusIcon = match($item->status ?? 'available') {
                                            'committed' => 'o-clipboard-document-list',
                                            'delivered' => 'o-truck',
                                            'in_use' => 'o-bolt',
                                            'returned' => 'o-check-circle',
                                            'cancelled' => 'o-x-circle',
                                            'lost' => 'o-exclamation-triangle',
                                            'damaged' => 'o-exclamation-triangle',
                                            default => 'o-check-circle'
                                        };
                                    @endphp
                                    <x-badge
                                        value="{{ ucfirst(str_replace('_', ' ', $item->status ?? 'available')) }}"
                                        class="{{ $statusClasses }}"
                                    >
                                        <x-slot:icon>
                                            <x-icon name="{{ $statusIcon }}" class="w-4 h-4 mr-2" />
                                        </x-slot:icon>
                                    </x-badge>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @if($item->tags && is_array($item->tags))
                                            @foreach($item->tags as $tag)
                                                <span class="badge badge-outline badge-sm">{{ $tag }}</span>
                                            @endforeach
                                        @else
                                            <span class="text-xs opacity-50">-</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center py-8 text-base-content/60">
                                    <x-icon name="o-wrench-screwdriver" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                                    <p>No equipment found</p>
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
    <x-modal wire:model="showPhotoModal" title="{{ $photoDescription ?? 'Equipment Detail' }}" class="backdrop-blur" box-class="max-w-4xl">
        @if($photoPath)
            <div class="flex justify-center items-center">
                <img
                    src="{{ asset('storage/' . $photoPath) }}"
                    alt="{{ $photoDescription ?? 'Equipment' }}"
                    class="max-w-full max-h-[70vh] object-contain rounded"
                />
            </div>
        @endif

        <x-slot:actions>
            <x-button label="Close" @click="$wire.showPhotoModal = false" class="btn-ghost" />
        </x-slot:actions>
    </x-modal>
</div>
