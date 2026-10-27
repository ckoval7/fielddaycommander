{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-slot:title>Equipment Catalog</x-slot:title>

    <div class="p-6 max-sm:px-0">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">
            <h1 class="text-3xl font-bold">Equipment Catalog</h1>
            @can('edit-any-equipment')
                <x-button
                    label="Add Equipment for Owner"
                    icon="phosphor-plus"
                    class="btn-primary btn-sm sm:btn-md"
                    link="{{ route('equipment.create', $userFilter && $userFilter !== 'club' ? ['for_user' => $userFilter] : []) }}"
                    wire:navigate
                />
            @endcan
        </div>

        <x-equipment.filters :filterCols="4">
            <x-choices-offline
                label="Owner"
                wire:model.live="userFilter"
                :options="$this->userOptions"
                placeholder="Search owner..."
                single
                searchable
            />
        </x-equipment.filters>

        {{-- Mobile Card View --}}
        <div class="xl:hidden space-y-3">
            @forelse($equipment as $item)
                <x-card wire:key="equipment-card-{{ $item->id }}" shadow class="!p-4">
                    <div class="flex items-start gap-3">
                        <x-equipment.photo-thumbnail :item="$item" size="lg" />
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-semibold">{{ $item->make }}</div>
                                    <div class="text-sm opacity-60">{{ $item->model }}</div>
                                </div>
                                @can('edit-any-equipment')
                                    @include('livewire.equipment.partials.all-equipment-actions', ['item' => $item])
                                @endcan
                            </div>
                            <div class="mt-1">
                                @if($item->is_club_equipment)
                                    <span class="badge badge-club badge-xs">
                                        <x-icon name="phosphor-buildings" class="w-3 h-3 mr-0.5" />
                                        Club
                                    </span>
                                    @if($item->managed_by_user_id && $item->manager)
                                        <span class="text-xs text-base-content/70 ml-1">Manager: {{ $item->manager->call_sign }}</span>
                                    @endif
                                @elseif($item->owner)
                                    <span class="text-sm font-semibold">{{ $item->owner->call_sign }}</span>
                                    @if($item->owner->first_name || $item->owner->last_name)
                                        <span class="text-xs text-base-content/70 ml-1">{{ trim($item->owner->first_name . ' ' . $item->owner->last_name) }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-base-200">
                        <span class="badge badge-primary badge-sm">{{ ucfirst(str_replace('_', ' ', $item->type)) }}</span>
                        <x-equipment.status-badge :status="$item->activeCommitments->first()?->status ?? 'available'" />
                        @if($item->serial_number)
                            <code class="text-xs text-base-content/70">{{ $item->serial_number }}</code>
                        @endif
                    </div>
                    @if($item->tags && is_array($item->tags) && count($item->tags))
                        <div class="mt-2">
                            <x-equipment.tags :tags="$item->tags" size="xs" />
                        </div>
                    @endif
                </x-card>
            @empty
                <x-equipment.empty-state />
            @endforelse

            @if($equipment->hasPages())
                <div class="pt-4">
                    {{ $equipment->links() }}
                </div>
            @endif
        </div>

        {{-- Desktop Table View --}}
        <x-card shadow class="hidden xl:block">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Equipment catalog">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th scope="col">Photo</th>
                            <th scope="col">Owner</th>
                            <th scope="col">Make/Model</th>
                            <th scope="col">Type</th>
                            <th scope="col">Description</th>
                            <th scope="col">Serial Number</th>
                            <th scope="col">Status</th>
                            <th scope="col">Tags</th>
                            @can('edit-any-equipment')
                                <th scope="col" class="text-right">Actions</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($equipment as $item)
                            <tr wire:key="equipment-{{ $item->id }}">
                                <td>
                                    <x-equipment.photo-thumbnail :item="$item" />
                                </td>
                                <td>
                                    @if($item->is_club_equipment)
                                        <span class="badge badge-club badge-sm">
                                            <x-icon name="phosphor-buildings" class="w-3 h-3 mr-0.5" />
                                            Club
                                        </span>
                                        @if($item->managed_by_user_id && $item->manager)
                                            <div class="text-xs text-base-content/70 mt-0.5">
                                                Manager: {{ $item->manager->call_sign }}
                                            </div>
                                        @endif
                                    @elseif($item->owner)
                                        <div class="font-semibold text-sm">{{ $item->owner->call_sign }}</div>
                                        @if($item->owner->first_name || $item->owner->last_name)
                                            <div class="text-xs text-base-content/70">
                                                {{ trim($item->owner->first_name . ' ' . $item->owner->last_name) }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-xs text-base-content/70">—</span>
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
                                    <x-equipment.status-badge :status="$item->activeCommitments->first()?->status ?? 'available'" />
                                </td>
                                <td>
                                    <x-equipment.tags :tags="$item->tags" />
                                </td>
                                @can('edit-any-equipment')
                                    <td class="text-right">
                                        @include('livewire.equipment.partials.all-equipment-actions', ['item' => $item])
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <x-equipment.empty-state colspan="{{ auth()->user()?->can('edit-any-equipment') ? 9 : 8 }}" />
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

    <x-equipment.photo-modal :photoPath="$photoPath" :photoDescription="$photoDescription" />

    @can('edit-any-equipment')
        @include('livewire.equipment.partials.commit-modal')
        @include('livewire.equipment.partials.commitment-details-modal')
        @include('livewire.equipment.partials.update-notes-modal')
    @endcan
</div>
