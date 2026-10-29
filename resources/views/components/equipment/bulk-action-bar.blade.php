{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{-- Selection bar for the equipment lists: commit the selected items to an event, delete them, or clear the selection. --}}
@props(['selectedIds'])

@if(count($selectedIds) > 0)
    <div class="flex flex-wrap items-center gap-3 mb-4 p-3 bg-primary/10 rounded-lg border border-primary/20">
        <span class="text-sm font-semibold">{{ count($selectedIds) }} {{ str('item')->plural(count($selectedIds)) }} selected</span>
        <div class="flex flex-wrap gap-2 w-full sm:w-auto">
            @php
                $allAvailable = \App\Models\Equipment::whereIn('id', $selectedIds)
                    ->whereDoesntHave('commitments', function ($q) {
                        $q->whereIn('status', ['committed', 'delivered'])
                            ->whereHas('event', fn ($eq) => $eq->notEnded());
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
                wire:confirm="Delete {{ count($selectedIds) }} selected {{ str('item')->plural(count($selectedIds)) }}?"
            />
            <x-button
                label="Clear Selection"
                icon="phosphor-x"
                class="btn-ghost btn-sm ml-auto sm:ml-0"
                wire:click="deselectAll"
            />
        </div>
    </div>
@endif
