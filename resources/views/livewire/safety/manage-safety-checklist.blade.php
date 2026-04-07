<div>
    <x-slot:title>Manage Safety Checklist{{ $event ? ' - ' . $event->name : '' }}</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <x-button
                        icon="o-arrow-left"
                        class="btn-ghost btn-sm"
                        link="{{ route('site-safety.index') }}"
                        tooltip="Back to Safety Checklist"
                    />
                    <h1 class="text-2xl md:text-3xl font-bold">Manage Safety Checklist</h1>
                </div>
                @if($event)
                    <p class="text-base-content/60">{{ $event->name }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <x-button
                    label="Add Item"
                    icon="o-plus"
                    class="btn-primary btn-sm"
                    wire:click="openItemModal"
                />
            </div>
        </div>

        @if(!$eventConfig)
            <x-alert icon="o-exclamation-triangle" class="alert-warning">
                No active event configuration found. Please configure an event first.
            </x-alert>
        @else
            {{-- Items list --}}
            @if($this->items->isEmpty())
                <x-card shadow>
                    <div class="text-center py-8 text-base-content/60">
                        <x-icon name="o-clipboard-document-check" class="w-12 h-12 mx-auto mb-3 opacity-30" />
                        <p class="text-lg font-medium">No checklist items yet</p>
                        <p class="text-sm">Add custom items to get started.</p>
                    </div>
                </x-card>
            @else
                <div class="space-y-3">
                    @foreach($this->items as $item)
                        <x-card shadow wire:key="item-{{ $item->id }}">
                            <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                                {{-- Reorder buttons --}}
                                <div class="flex flex-col gap-1 shrink-0">
                                    <x-button
                                        icon="o-chevron-up"
                                        class="btn-ghost btn-xs"
                                        wire:click="moveUp({{ $item->id }})"
                                        tooltip="Move Up"
                                    />
                                    <x-button
                                        icon="o-chevron-down"
                                        class="btn-ghost btn-xs"
                                        wire:click="moveDown({{ $item->id }})"
                                        tooltip="Move Down"
                                    />
                                </div>

                                {{-- Item details --}}
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap mb-1">
                                        <span class="font-medium">{{ $item->label }}</span>
                                    </div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        @if($item->is_required)
                                            <x-badge value="Required" class="badge-error badge-sm" />
                                        @endif
                                        @if($item->is_default)
                                            <x-badge value="Default" class="badge-neutral badge-sm badge-outline" />
                                        @endif
                                        <x-badge value="{{ $item->checklist_type->label() }}" class="badge-info badge-sm badge-outline" />
                                        @if($item->entry?->is_completed)
                                            <span class="text-success text-sm">&#10003; Completed</span>
                                        @else
                                            <span class="text-base-content/40 text-sm">&#9675; Pending</span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Action buttons --}}
                                <div class="flex items-center gap-1 shrink-0">
                                    <x-button
                                        icon="o-pencil"
                                        class="btn-ghost btn-sm"
                                        wire:click="openItemModal({{ $item->id }})"
                                        tooltip="Edit Item"
                                    />
                                    <x-button
                                        icon="o-trash"
                                        class="btn-ghost btn-sm text-error"
                                        wire:click="deleteItem({{ $item->id }})"
                                        wire:confirm="Delete this checklist item?"
                                        tooltip="Delete Item"
                                        :disabled="$item->is_default"
                                    />
                                </div>
                            </div>
                        </x-card>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    {{-- Item Form Modal --}}
    <x-modal wire:model="showItemModal" title="{{ $editingItemId ? 'Edit Item' : 'Add Item' }}">
        <div>
            <div class="space-y-4">
                <x-input
                    label="Label"
                    wire:model="itemLabel"
                    placeholder="e.g., Fire extinguisher on hand"
                    required
                />
                <x-textarea
                    label="Help Text"
                    wire:model="itemHelpText"
                    placeholder="Optional guidance shown when users click the ? button"
                    rows="4"
                    hint="Displayed as an expandable tip on the checklist"
                />
                <x-toggle
                    label="Required"
                    wire:model="itemIsRequired"
                    hint="Mark this item as required for checklist completion"
                />
                @if(!$editingItemId)
                    <x-select
                        label="Checklist Type"
                        wire:model="itemChecklistType"
                        :options="collect(\App\Enums\ChecklistType::cases())->map(fn ($type) => ['id' => $type->value, 'name' => $type->label()])"
                        option-value="id"
                        option-label="name"
                        placeholder="Select a type"
                        required
                    />
                @endif
            </div>

            <x-slot:actions>
                <x-button label="Cancel" @click="$wire.showItemModal = false" />
                <x-button label="Save" wire:click="saveItem" class="btn-primary" spinner="saveItem" />
            </x-slot:actions>
        </div>
    </x-modal>
</div>
