<div>
    <x-slot:title>Club Equipment</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">
            <h1 class="text-3xl font-bold">Club Equipment</h1>
            @can('edit-any-equipment')
                <x-button label="Add Club Equipment" icon="phosphor-plus" class="btn-primary btn-sm sm:btn-md" link="{{ route('equipment.create', ['club' => true]) }}" wire:navigate />
            @endcan
        </div>

        <x-equipment.filters />

        {{-- Bulk Action Bar --}}
        @can('edit-any-equipment')
            @if(count($selectedIds) > 0)
                <div class="flex items-center gap-4 mb-4 p-3 bg-primary/10 rounded-lg border border-primary/20">
                    <span class="text-sm font-semibold">{{ count($selectedIds) }} item(s) selected</span>
                    <div class="flex gap-2">
                        @php
                            $allAvailable = \App\Models\Equipment::whereIn('id', $selectedIds)
                                ->whereDoesntHave('commitments', function ($q) {
                                    $q->whereIn('status', ['committed', 'delivered'])
                                        ->whereHas('event', function ($eq) {
                                            $eq->where('start_time', '<=', now()->addDays(30))
                                                ->where('end_time', '>=', now());
                                        });
                                })
                                ->count() === count($selectedIds);
                        @endphp
                        <x-button
                            label="Commit to Event"
                            icon="phosphor-calendar"
                            class="btn-primary btn-sm"
                            wire:click="openBulkCommitModal"
                            :disabled="!$allAvailable"
                            :title="$allAvailable ? '' : 'Some selected items already have active commitments'"
                        />
                        <x-button
                            label="Delete"
                            icon="phosphor-trash"
                            class="btn-error btn-sm"
                            wire:click="bulkDeleteEquipment"
                            wire:confirm="Are you sure you want to delete {{ count($selectedIds) }} item(s)?"
                        />
                    </div>
                    <x-button
                        label="Clear"
                        icon="phosphor-x"
                        class="btn-ghost btn-sm ml-auto"
                        wire:click="deselectAll"
                    />
                </div>
            @endif
        @endcan

        {{-- Mobile Card View --}}
        <div class="md:hidden space-y-3">
            @forelse($equipment as $item)
                <x-card wire:key="equipment-card-{{ $item->id }}" shadow class="!p-4">
                    <div class="flex items-start gap-3">
                        @can('edit-any-equipment')
                            <label class="flex items-center pt-1">
                                <span class="sr-only">Select {{ $item->make }} {{ $item->model }}</span>
                                <input type="checkbox" class="checkbox checkbox-sm" value="{{ $item->id }}" wire:model.live="selectedIds" />
                            </label>
                        @endcan
                        <x-equipment.photo-thumbnail :item="$item" size="lg" />
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="font-semibold">{{ $item->make }}</div>
                                    <div class="text-sm opacity-60">{{ $item->model }}</div>
                                </div>
                                @can('edit-any-equipment')
                                    <x-dropdown>
                                        <x-slot:trigger>
                                            <x-button icon="phosphor-dots-three-vertical" class="btn-sm btn-ghost" />
                                        </x-slot:trigger>
                                        <x-menu-item title="Edit" icon="phosphor-pencil-simple" link="{{ route('equipment.edit', $item) }}" wire:navigate />
                                        @if($item->currentCommitment)
                                            <x-menu-separator />
                                            <x-menu-item title="View Commitment" icon="phosphor-eye" wire:click="openDetailsModal({{ $item->currentCommitment->id }})" />
                                            <x-menu-item title="Update Notes" icon="phosphor-note-pencil" wire:click="openNotesModal({{ $item->currentCommitment->id }})" />
                                            <x-menu-separator />
                                            @foreach(\App\Models\EquipmentEvent::STATUSES as $status)
                                                @if($status !== $item->currentCommitment->status)
                                                    <x-menu-item
                                                        title="{{ ucfirst(str_replace('_', ' ', $status)) }}"
                                                        wire:click="changeStatus({{ $item->currentCommitment->id }}, '{{ $status }}')"
                                                    />
                                                @endif
                                            @endforeach
                                        @else
                                            <x-menu-item title="Commit to Event" icon="phosphor-calendar" wire:click="openCommitModal({{ $item->id }})" />
                                        @endif
                                        <x-menu-separator />
                                        <x-menu-item title="Delete" icon="phosphor-trash" class="text-error" wire:click="deleteEquipment({{ $item->id }})" wire:confirm="Are you sure you want to delete this equipment?" />
                                    </x-dropdown>
                                @endcan
                            </div>
                            @if($item->managed_by_user_id && $item->manager)
                                <div class="text-xs opacity-70 mt-1">Managed by {{ $item->manager->call_sign }}</div>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-base-200">
                        <span class="badge badge-primary badge-sm">{{ ucfirst(str_replace('_', ' ', $item->type)) }}</span>
                        <x-equipment.status-badge :status="$item->currentCommitment?->status ?? 'available'" />
                        @if($item->serial_number)
                            <code class="text-xs opacity-70">{{ $item->serial_number }}</code>
                        @endif
                    </div>
                    @if($item->tags && is_array($item->tags) && count($item->tags))
                        <div class="mt-2">
                            <x-equipment.tags :tags="$item->tags" size="xs" />
                        </div>
                    @endif
                </x-card>
            @empty
                <x-equipment.empty-state message="No club equipment found" />
            @endforelse

            @if($equipment->hasPages())
                <div class="pt-4">
                    {{ $equipment->links() }}
                </div>
            @endif
        </div>

        {{-- Desktop Table View --}}
        <x-card shadow class="hidden md:block">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            @can('edit-any-equipment')
                                <th>
                                    <label>
                                        <span class="sr-only">Select all</span>
                                        <input
                                            type="checkbox"
                                            class="checkbox checkbox-sm"
                                            x-on:change="
                                                if ($el.checked) {
                                                    $wire.selectAll();
                                                } else {
                                                    $wire.deselectAll();
                                                }
                                            "
                                            :checked="$wire.selectedIds.length > 0 && $wire.selectedIds.length === $wire.equipment?.data?.length"
                                        />
                                    </label>
                                </th>
                            @endcan
                            <th>Photo</th>
                            <th>Make/Model</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Serial Number</th>
                            <th>Manager</th>
                            <th>Status</th>
                            <th>Tags</th>
                            @can('edit-any-equipment')
                                <th class="text-right">Actions</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($equipment as $item)
                            <tr wire:key="equipment-{{ $item->id }}">
                                @can('edit-any-equipment')
                                    <td>
                                        <label>
                                            <span class="sr-only">Select {{ $item->make }} {{ $item->model }}</span>
                                            <input
                                                type="checkbox"
                                                class="checkbox checkbox-sm"
                                                value="{{ $item->id }}"
                                                wire:model.live="selectedIds"
                                            />
                                        </label>
                                    </td>
                                @endcan
                                <td>
                                    <x-equipment.photo-thumbnail :item="$item" />
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
                                    @if($item->managed_by_user_id && $item->manager)
                                        <div class="text-sm">{{ $item->manager->call_sign }}</div>
                                    @else
                                        <span class="text-xs opacity-50">-</span>
                                    @endif
                                </td>
                                <td>
                                    <x-equipment.status-badge :status="$item->currentCommitment?->status ?? 'available'" />
                                </td>
                                <td>
                                    <x-equipment.tags :tags="$item->tags" />
                                </td>
                                @can('edit-any-equipment')
                                    <td class="text-right">
                                        <x-dropdown>
                                            <x-slot:trigger>
                                                <x-button icon="phosphor-dots-three-vertical" class="btn-sm btn-ghost" />
                                            </x-slot:trigger>

                                            <x-menu-item title="Edit" icon="phosphor-pencil-simple" link="{{ route('equipment.edit', $item) }}" wire:navigate />

                                            @if($item->currentCommitment)
                                                <x-menu-separator />
                                                <x-menu-item title="View Commitment" icon="phosphor-eye" wire:click="openDetailsModal({{ $item->currentCommitment->id }})" />
                                                <x-menu-item title="Update Notes" icon="phosphor-note-pencil" wire:click="openNotesModal({{ $item->currentCommitment->id }})" />
                                                <x-menu-separator />
                                                @foreach(\App\Models\EquipmentEvent::STATUSES as $status)
                                                    @if($status !== $item->currentCommitment->status)
                                                        <x-menu-item
                                                            title="{{ ucfirst(str_replace('_', ' ', $status)) }}"
                                                            wire:click="changeStatus({{ $item->currentCommitment->id }}, '{{ $status }}')"
                                                        />
                                                    @endif
                                                @endforeach
                                            @else
                                                <x-menu-item title="Commit to Event" icon="phosphor-calendar" wire:click="openCommitModal({{ $item->id }})" />
                                            @endif

                                            <x-menu-separator />
                                            <x-menu-item title="Delete" icon="phosphor-trash" class="text-error" wire:click="deleteEquipment({{ $item->id }})" wire:confirm="Are you sure you want to delete this equipment?" />
                                        </x-dropdown>
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <x-equipment.empty-state colspan="{{ auth()->user()?->can('edit-any-equipment') ? 10 : 8 }}" message="No club equipment found" />
                        @endforelse
                    </tbody>
                </table>
            {{-- Pagination --}}
            @if($equipment->hasPages())
                <div class="p-4">
                    {{ $equipment->links() }}
                </div>
            @endif
        </x-card>
    </div>

    <x-equipment.photo-modal :photoPath="$photoPath" :photoDescription="$photoDescription" />

    @can('edit-any-equipment')
        @include('livewire.equipment.partials.commit-modal')
        @include('livewire.equipment.partials.bulk-commit-modal')
        @include('livewire.equipment.partials.commitment-details-modal')
        @include('livewire.equipment.partials.update-notes-modal')
    @endcan
</div>
