{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-slot:title>Manage Checklists{{ $event ? ' - ' . $event->name : '' }}</x-slot:title>

    <div class="p-6 max-sm:px-0">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <x-button
                        icon="phosphor-arrow-left"
                        class="btn-ghost btn-sm btn-square"
                        link="{{ route('checklists.index') }}"
                        aria-label="Back to Checklists"
                    />
                    <h1 class="text-2xl md:text-3xl font-bold">Manage Checklists</h1>
                </div>
                @if($event)
                    <p class="text-base-content/60">{{ $event->name }}</p>
                @endif
            </div>
            @if($eventConfig)
                <x-button label="Add Checklist" icon="phosphor-plus" class="btn-primary max-sm:w-full" wire:click="openAddModal" />
            @endif
        </div>

        @if(! $eventConfig)
            <x-alert icon="phosphor-warning" class="alert-warning">
                Set up an event before adding checklists.
            </x-alert>
        @elseif($this->checklists->isEmpty())
            <x-card shadow>
                <div class="text-center py-8 text-base-content/60">
                    <x-icon name="phosphor-clipboard-text" class="w-12 h-12 mx-auto mb-3 opacity-30" />
                    <p class="text-lg font-medium">No checklists yet</p>
                    <p class="text-sm">Add one from a template, copy one from a past event, or start a blank one.</p>
                </div>
            </x-card>
        @else
            <div class="space-y-3">
                @foreach($this->checklists as $checklist)
                    <x-card shadow wire:key="checklist-{{ $checklist->id }}">
                        <div class="flex items-start sm:items-center gap-3">
                            <div class="flex flex-col gap-1 shrink-0">
                                <x-button icon="phosphor-caret-up" class="btn-ghost btn-xs pointer-coarse:min-w-11" wire:click="moveUp({{ $checklist->id }})" aria-label="Move {{ $checklist->name }} up" :disabled="$loop->first" />
                                <x-button icon="phosphor-caret-down" class="btn-ghost btn-xs pointer-coarse:min-w-11" wire:click="moveDown({{ $checklist->id }})" aria-label="Move {{ $checklist->name }} down" :disabled="$loop->last" />
                            </div>

                            <div class="flex-1 min-w-0">
                                <p class="font-medium break-words">{{ $checklist->name }}</p>
                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    <x-badge :value="$checklist->scope->label()" class="badge-info badge-sm badge-outline" />
                                    <x-badge :value="$checklist->items_count . ' ' . Str::plural('item', $checklist->items_count)" class="badge-neutral badge-sm badge-outline" />
                                    @if($checklist->earnsBonus())
                                        <x-badge value="Earns a bonus" class="badge-warning badge-sm" />
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row items-center gap-2 shrink-0">
                                <x-button icon="phosphor-pencil-simple" class="btn-ghost btn-sm" link="{{ route('checklists.edit', $checklist) }}" aria-label="Edit {{ $checklist->name }}" />
                                <x-button
                                    icon="phosphor-trash"
                                    class="btn-ghost btn-sm text-error"
                                    wire:click="deleteChecklist({{ $checklist->id }})"
                                    wire:confirm="Delete {{ $checklist->name }}? {{ $checklist->runs_count > 0 ? 'Its answers are removed with it.' : '' }}{{ $checklist->earnsBonus() ? ' The bonus it earned is removed too.' : '' }}"
                                    aria-label="Delete {{ $checklist->name }}"
                                />
                            </div>
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif
    </div>

    <x-modal wire:model="showAddModal" title="Add Checklist" box-class="max-h-[90dvh]">
        <div class="space-y-4">
            <div class="join w-full" role="group" aria-label="Start from">
                @foreach(['template' => 'Template', 'copy' => 'Past event', 'blank' => 'Blank'] as $source => $sourceLabel)
                    <button
                        type="button"
                        class="join-item btn btn-sm flex-1 touch-manipulation {{ $addSource === $source ? 'btn-primary' : 'btn-outline' }}"
                        aria-pressed="{{ $addSource === $source ? 'true' : 'false' }}"
                        wire:click="$set('addSource', '{{ $source }}')"
                    >{{ $sourceLabel }}</button>
                @endforeach
            </div>

            @if($addSource === 'template')
                <x-select
                    label="Template"
                    wire:model="templateKey"
                    :options="collect($this->templateOptions)->map(fn ($name, $key) => ['id' => $key, 'name' => $name])->values()"
                    placeholder="Select a template"
                />
            @elseif($addSource === 'copy')
                @if($this->copyOptions->isEmpty())
                    <p class="text-sm text-base-content/70">No other event has a checklist to copy yet.</p>
                @else
                    <x-select
                        label="Checklist to copy"
                        wire:model="copyChecklistId"
                        :options="$this->copyOptions"
                        placeholder="Select a checklist"
                        hint="Copies the items and settings, not the answers."
                    />
                @endif
            @else
                <x-input label="Name" wire:model="blankName" autocapitalize="words" placeholder="e.g., Aid station opening" />
                <x-select
                    label="Checklist scope"
                    wire:model="blankScope"
                    :options="collect(\App\Enums\ChecklistScope::cases())->map(fn ($scope) => ['id' => $scope->value, 'name' => $scope->label()])"
                />
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.showAddModal = false" />
            <x-button label="Add Checklist" wire:click="addChecklist" class="btn-primary" spinner="addChecklist" />
        </x-slot:actions>
    </x-modal>
</div>
