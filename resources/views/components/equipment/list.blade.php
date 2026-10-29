{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
    Card list and table for My Equipment, Club Equipment and the Equipment Catalog.

    - equipment: the page's paginator, with activeCommitments eager-loaded
    - selectable: show the selection checkboxes (the page has a bulk action bar)
    - showActions: show the per-item actions menu
    - ownership: how owner and manager show
        'mine': club badge and the manager's full name under make and model (My Equipment)
        'club': Manager column, and "Managed by" with the call sign on cards (Club Equipment)
        'all':  Owner column, and the owner or club badge with manager on cards (Equipment Catalog)
    - emptyMessage, emptyActionLabel, emptyActionRoute: what to show when nothing matches

    Items show as cards until the table fits beside the open sidebar at 2xl. The table has
    no overflow wrapper, so the actions dropdowns aren't clipped.
--}}
@props([
    'equipment',
    'selectable' => false,
    'showActions' => false,
    'ownership' => 'mine',
    'emptyMessage',
    'emptyActionLabel' => null,
    'emptyActionRoute' => null,
])

@php
    $columnCount = 7 + (in_array($ownership, ['club', 'all'], true) ? 1 : 0) + ($selectable ? 1 : 0) + ($showActions ? 1 : 0);
@endphp

{{-- Card View until the table fits --}}
<div class="2xl:hidden grid grid-cols-1 xl:grid-cols-2 gap-3 items-start">
    @forelse($equipment as $item)
        @php($commitment = $item->activeCommitments->first())
        <x-card wire:key="equipment-card-{{ $item->id }}" shadow class="!p-4">
            <div class="flex items-start gap-3">
                @if($selectable)
                    <label class="flex items-center pt-1">
                        <span class="sr-only">Select {{ $item->make }} {{ $item->model }}</span>
                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $item->id }}" wire:model.live="selectedIds" />
                    </label>
                @endif
                <x-equipment.photo-thumbnail :item="$item" size="lg" />
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold">{{ $item->make }}</div>
                            <div class="text-sm opacity-60">{{ $item->model }}</div>
                        </div>
                        @if($showActions)
                            <x-equipment.actions-menu :item="$item" :commitment="$commitment" />
                        @endif
                    </div>
                    @if($ownership === 'all')
                        <div class="mt-1">
                            @if($item->is_club_equipment)
                                <span class="badge badge-club badge-xs">
                                    <x-icon name="phosphor-buildings" class="w-3 h-3 mr-0.5" />
                                    Club
                                </span>
                                @if($item->managed_by_user_id && $item->manager)
                                    <span class="text-xs opacity-70 ml-1">Manager: {{ $item->manager->call_sign }}</span>
                                @endif
                            @elseif($item->owner)
                                <span class="text-sm font-semibold">{{ $item->owner->call_sign }}</span>
                                @if($item->owner->first_name || $item->owner->last_name)
                                    <span class="text-xs opacity-70 ml-1">{{ trim($item->owner->first_name . ' ' . $item->owner->last_name) }}</span>
                                @endif
                            @endif
                        </div>
                    @elseif($ownership === 'club')
                        @if($item->managed_by_user_id && $item->manager)
                            <div class="text-xs opacity-70 mt-1">Managed by {{ $item->manager->call_sign }}</div>
                        @endif
                    @elseif($item->is_club_equipment)
                        <span class="badge badge-club badge-xs mt-1">
                            <x-icon name="phosphor-buildings" class="w-3 h-3 mr-0.5" />
                            Club Equipment
                        </span>
                        @if($item->managed_by_user_id && $item->manager)
                            <div class="text-xs opacity-70">Managed by {{ $item->manager->full_name }}</div>
                        @endif
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-base-200">
                <span class="badge badge-primary badge-sm">{{ ucfirst(str_replace('_', ' ', $item->type)) }}</span>
                <x-equipment.status-badge :status="$commitment?->status ?? 'available'" />
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
        <div class="xl:col-span-2">
            <x-equipment.empty-state :message="$emptyMessage" :action-label="$emptyActionLabel" :action-route="$emptyActionRoute" />
        </div>
    @endforelse

    @if($equipment->hasPages())
        <div class="pt-4 xl:col-span-2">
            {{ $equipment->links() }}
        </div>
    @endif
</div>

{{-- Desktop Table View: it needs about 1,100px, so it only fits beside the open sidebar from 2xl --}}
<x-card shadow class="hidden 2xl:block">
    <table class="table table-zebra">
        <thead>
            <tr>
                @if($selectable)
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
                @endif
                <th>Photo</th>
                @if($ownership === 'all')
                    <th>Owner</th>
                @endif
                <th>Make/Model</th>
                <th>Type</th>
                <th>Description</th>
                <th>Serial Number</th>
                @if($ownership === 'club')
                    <th>Manager</th>
                @endif
                <th>Status</th>
                <th>Tags</th>
                @if($showActions)
                    <th class="text-right">Actions</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($equipment as $item)
                @php($commitment = $item->activeCommitments->first())
                <tr wire:key="equipment-{{ $item->id }}">
                    @if($selectable)
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
                    @endif
                    <td>
                        <x-equipment.photo-thumbnail :item="$item" />
                    </td>
                    @if($ownership === 'all')
                        <td>
                            @if($item->is_club_equipment)
                                <span class="badge badge-club badge-sm">
                                    <x-icon name="phosphor-buildings" class="w-3 h-3 mr-0.5" />
                                    Club
                                </span>
                                @if($item->managed_by_user_id && $item->manager)
                                    <div class="text-xs opacity-70 mt-0.5">
                                        Manager: {{ $item->manager->call_sign }}
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
                    @endif
                    <td class="font-semibold">
                        <div>{{ $item->make }}</div>
                        <div class="text-sm opacity-60">{{ $item->model }}</div>
                        @if($ownership === 'mine' && $item->is_club_equipment)
                            <div class="mt-1">
                                <span class="badge badge-club badge-xs">
                                    <x-icon name="phosphor-buildings" class="w-3 h-3 mr-0.5" />
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
                        <span class="badge badge-primary badge-sm whitespace-nowrap">
                            {{ ucfirst(str_replace('_', ' ', $item->type)) }}
                        </span>
                    </td>
                    <td class="min-w-40 max-w-xs">
                        <p class="line-clamp-2 text-sm">{{ $item->description ?? '-' }}</p>
                    </td>
                    <td>
                        <code class="text-xs">{{ $item->serial_number ?? '-' }}</code>
                    </td>
                    @if($ownership === 'club')
                        <td>
                            @if($item->managed_by_user_id && $item->manager)
                                <div class="text-sm">{{ $item->manager->call_sign }}</div>
                            @else
                                <span class="text-xs opacity-50">-</span>
                            @endif
                        </td>
                    @endif
                    <td>
                        <x-equipment.status-badge :status="$commitment?->status ?? 'available'" />
                    </td>
                    <td>
                        <x-equipment.tags :tags="$item->tags" />
                    </td>
                    @if($showActions)
                        <td class="text-right">
                            <x-equipment.actions-menu :item="$item" :commitment="$commitment" />
                        </td>
                    @endif
                </tr>
            @empty
                <x-equipment.empty-state :colspan="$columnCount" :message="$emptyMessage" :action-label="$emptyActionLabel" :action-route="$emptyActionRoute" />
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
