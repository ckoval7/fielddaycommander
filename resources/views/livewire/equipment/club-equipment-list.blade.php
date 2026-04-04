<div>
    <x-slot:title>Club Equipment</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Club Equipment</h1>
            @can('edit-any-equipment')
                <x-button label="Add Club Equipment" icon="o-plus" class="btn-primary" link="{{ route('equipment.create', ['club' => true]) }}" wire:navigate />
            @endcan
        </div>

        <x-equipment.filters />

        {{-- Mobile Card View --}}
        <div class="md:hidden space-y-3">
            @forelse($equipment as $item)
                <x-card wire:key="equipment-card-{{ $item->id }}" shadow class="!p-4">
                    <div class="flex items-start gap-3">
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
                                            <x-button icon="o-ellipsis-vertical" class="btn-sm btn-ghost" />
                                        </x-slot:trigger>
                                        <x-menu-item title="Edit" icon="o-pencil" link="{{ route('equipment.edit', $item) }}" wire:navigate />
                                        <x-menu-separator />
                                        <x-menu-item title="Delete" icon="o-trash" class="text-error" wire:click="deleteEquipment({{ $item->id }})" wire:confirm="Are you sure you want to delete this equipment?" />
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
                                    <x-equipment.status-badge :status="$item->activeCommitments->first()?->status ?? 'available'" />
                                </td>
                                <td>
                                    <x-equipment.tags :tags="$item->tags" />
                                </td>
                                @can('edit-any-equipment')
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
                                @endcan
                            </tr>
                        @empty
                            <x-equipment.empty-state colspan="{{ auth()->user()?->can('edit-any-equipment') ? 9 : 8 }}" message="No club equipment found" />
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
</div>
