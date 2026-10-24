{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-slot:title>Edit {{ $checklist->name }}</x-slot:title>

    <div class="p-6 max-sm:px-0 space-y-6">
        <div class="flex items-center gap-2">
            <x-button icon="phosphor-arrow-left" class="btn-ghost btn-sm btn-square" link="{{ route('checklists.manage') }}" aria-label="Back to Manage Checklists" />
            <h1 class="text-2xl md:text-3xl font-bold break-words min-w-0">Edit {{ $checklist->name }}</h1>
        </div>

        <x-card title="Settings" shadow>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input label="Name" wire:model="name" autocapitalize="words" required />
                <x-select
                    label="Checklist scope"
                    wire:model="scope"
                    :options="collect(\App\Enums\ChecklistScope::cases())->map(fn ($s) => ['id' => $s->value, 'name' => $s->label()])"
                    :disabled="$this->scopeLocked"
                    :hint="$this->scopeLocked ? 'Can’t change after someone starts this checklist.' : 'Per-station checklists also show in station mode.'"
                />
                <div class="md:col-span-2">
                    <x-textarea label="Description" wire:model="description" rows="2" placeholder="What this checklist is for" />
                </div>
                <x-select
                    label="Completion rule"
                    wire:model="completionRule"
                    :options="collect(\App\Enums\ChecklistCompletionRule::cases())->map(fn ($r) => ['id' => $r->value, 'name' => $r->label()])"
                    :disabled="(bool) $checklist->bonus_code"
                    :hint="$checklist->bonus_code ? 'Fixed for this ARRL bonus. ARRL items can’t be deleted.' : null"
                />
                <div>
                    <fieldset>
                        <legend class="block py-1 text-sm font-semibold">Who can complete it</legend>
                        @if($this->shiftRoleOptions->isEmpty())
                            <p class="text-sm text-base-content/70">Anyone with a shift at this event or permission to manage the schedule can answer. Add shift roles to limit who can answer.</p>
                        @else
                            <div class="flex flex-col gap-1">
                                @foreach($this->shiftRoleOptions as $role)
                                    <label class="flex items-center gap-2 min-h-11 cursor-pointer" wire:key="role-{{ $role->id }}">
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $role->id }}" wire:model="shiftRoleIds" />
                                        <span class="text-sm">{{ $role->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="text-xs text-base-content/60 mt-1">Leave all unchecked to allow anyone with a shift at this event or permission to manage the schedule to answer.</p>
                        @endif
                    </fieldset>
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Save Settings" class="btn-primary max-sm:w-full" wire:click="saveSettings" spinner="saveSettings" />
            </x-slot:actions>
        </x-card>

        <x-card title="Items" shadow>
            <x-slot:menu>
                <x-button label="Add Item" icon="phosphor-plus" class="btn-primary btn-sm" wire:click="openItemModal" />
            </x-slot:menu>

            @if($this->items->isEmpty())
                <p class="text-center py-6 text-base-content/60">No items yet. Add the first one.</p>
            @else
                <div class="space-y-3">
                    @foreach($this->items as $item)
                        <div wire:key="item-{{ $item->id }}" class="flex items-start sm:items-center gap-3 p-3 rounded-lg bg-base-200/50 border border-base-300">
                            <div class="flex flex-col gap-1 shrink-0">
                                <x-button icon="phosphor-caret-up" class="btn-ghost btn-xs pointer-coarse:min-w-11" wire:click="moveUp({{ $item->id }})" aria-label="Move up: {{ $item->label }}" :disabled="$loop->first" />
                                <x-button icon="phosphor-caret-down" class="btn-ghost btn-xs pointer-coarse:min-w-11" wire:click="moveDown({{ $item->id }})" aria-label="Move down: {{ $item->label }}" :disabled="$loop->last" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium break-words">{{ $item->label }}</p>
                                <div class="flex flex-wrap items-center gap-2 mt-1">
                                    <x-badge :value="$item->answer_type->label()" class="badge-info badge-sm badge-outline" />
                                    @if($item->is_required)
                                        <x-badge value="Required" class="badge-error badge-sm" />
                                    @endif
                                    @if($item->is_default)
                                        <x-badge value="ARRL item" class="badge-neutral badge-sm badge-outline" />
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-col sm:flex-row items-center gap-2 shrink-0">
                                <x-button icon="phosphor-pencil-simple" class="btn-ghost btn-sm" wire:click="openItemModal({{ $item->id }})" aria-label="Edit: {{ $item->label }}" />
                                @unless($item->is_default)
                                    <x-button
                                        icon="phosphor-trash"
                                        class="btn-ghost btn-sm text-error"
                                        wire:click="deleteItem({{ $item->id }})"
                                        wire:confirm="Delete this item? Its answers are removed with it."
                                        aria-label="Delete: {{ $item->label }}"
                                    />
                                @endunless
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>
    </div>

    <x-modal wire:model="showItemModal" title="{{ $editingItemId ? 'Edit Item' : 'Add Item' }}" box-class="max-h-[90dvh]">
        <div class="space-y-4">
            <x-input label="Item text" wire:model="itemLabel" autocapitalize="sentences" placeholder="e.g., Radio check completed" required />
            <x-textarea label="Help text" wire:model="itemHelpText" rows="4" placeholder="Optional guidance for this item" hint="Shown when someone taps the ? button on the checklist." />
            <x-select
                label="Answer type"
                wire:model="itemAnswerType"
                :options="collect(\App\Enums\ChecklistAnswerType::cases())->map(fn ($t) => ['id' => $t->value, 'name' => $t->label()])"
            />
            <x-toggle label="Required" wire:model="itemIsRequired" />
        </div>

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.showItemModal = false" />
            <x-button label="Save Item" wire:click="saveItem" class="btn-primary" spinner="saveItem" />
        </x-slot:actions>
    </x-modal>
</div>
