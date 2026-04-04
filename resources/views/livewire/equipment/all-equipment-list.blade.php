<div>
    <x-slot:title>All User Catalogs</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-6">
            <h1 class="text-3xl font-bold">All User Catalogs</h1>
            @can('edit-any-equipment')
                <x-button
                    label="Add Equipment for User"
                    icon="o-plus"
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
        <div class="md:hidden space-y-3">
            @forelse($equipment as $item)
                <x-card wire:key="equipment-card-{{ $item->id }}" shadow class="!p-4">
                    <div class="flex items-start gap-3">
                        <x-equipment.photo-thumbnail :item="$item" size="lg" />
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold">{{ $item->make }}</div>
                            <div class="text-sm opacity-60">{{ $item->model }}</div>
                            <div class="mt-1">
                                @if($item->is_club_equipment)
                                    <span class="badge badge-club badge-xs">
                                        <x-icon name="o-building-office" class="w-3 h-3 mr-0.5" />
                                        Club
                                    </span>
                                    @if($item->managed_by_user_id && $item->manager)
                                        <span class="text-xs opacity-70 ml-1">Mgr: {{ $item->manager->call_sign }}</span>
                                    @endif
                                @elseif($item->owner)
                                    <span class="text-sm font-semibold">{{ $item->owner->call_sign }}</span>
                                    @if($item->owner->first_name || $item->owner->last_name)
                                        <span class="text-xs opacity-70 ml-1">{{ trim($item->owner->first_name . ' ' . $item->owner->last_name) }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-base-200">
                        <span class="badge badge-primary badge-sm">{{ ucfirst(str_replace('_', ' ', $item->type)) }}</span>
                        <x-equipment.status-badge :status="$item->activeCommitments->first()?->status ?? 'available'" />
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
                <x-equipment.empty-state />
            @endforelse

            @if($equipment->hasPages())
                <div class="pt-4">
                    {{ $equipment->links() }}
                </div>
            @endif
        </div>

        {{-- Desktop Table View --}}
        <x-card shadow class="hidden md:block">
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
                                    <x-equipment.photo-thumbnail :item="$item" />
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
                                    <x-equipment.status-badge :status="$item->activeCommitments->first()?->status ?? 'available'" />
                                </td>
                                <td>
                                    <x-equipment.tags :tags="$item->tags" />
                                </td>
                            </tr>
                        @empty
                            <x-equipment.empty-state colspan="8" />
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
</div>
