<div>
    {{-- Dashboard Header --}}
    @if($editMode)
        {{-- Edit Mode: Editable Title/Description --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-4">
            <div class="min-w-0 flex-1">
                {{-- Editable Dashboard Title --}}
                <div class="form-control">
                    <label class="label">
                        <span class="label-text text-xs font-medium">Dashboard Name</span>
                    </label>
                    <input
                        type="text"
                        wire:model="title"
                        placeholder="Dashboard name"
                        class="input input-bordered w-full"
                        maxlength="255"
                    />
                    @error('title')
                        <label class="label">
                            <span class="label-text-alt text-error">{{ $message }}</span>
                        </label>
                    @enderror
                </div>

                {{-- Editable Dashboard Description --}}
                <div class="form-control mt-2">
                    <label class="label">
                        <span class="label-text text-xs font-medium">Description (optional)</span>
                    </label>
                    <textarea
                        wire:model="description"
                        placeholder="Brief description of this dashboard"
                        class="textarea textarea-bordered w-full"
                        rows="2"
                        maxlength="1000"
                    ></textarea>
                    @error('description')
                        <label class="label">
                            <span class="label-text-alt text-error">{{ $message }}</span>
                        </label>
                    @enderror
                </div>
            </div>

            <div class="flex flex-wrap gap-2 flex-shrink-0">
                <x-button
                    label="Save"
                    icon="o-check"
                    class="btn-primary btn-sm min-h-[2.75rem] sm:min-h-[1.75rem]"
                    wire:click="saveLayout"
                    spinner="saveLayout"
                />
                <x-button
                    label="Cancel"
                    icon="o-x-mark"
                    class="btn-ghost btn-sm min-h-[2.75rem] sm:min-h-[1.75rem]"
                    wire:click="cancelEdit"
                    spinner="cancelEdit"
                />
            </div>
        </div>

        {{-- Edit Mode Banner --}}
        <x-alert icon="o-arrows-up-down" class="alert-info mb-4" dismissible>
            <span class="font-medium">Edit Mode</span> - Drag widgets to reorder, or use the controls to show, hide, configure, or remove widgets.
        </x-alert>

    {{-- Widget Grid with Sortable Integration (Edit Mode Only) --}}
    <div
        x-data="dashboardSortable($wire, @js($this->widgetIds))"
        @widgets-reordered.window="resetOrder($event.detail.widgetIds)"
        @edit-mode-changed.window="setEnabled($event.detail.enabled)"
        x-init="setEnabled(@js($editMode))"
        wire:key="editor-sortable-{{ $dashboard->id }}"
    >
        {{-- Screen reader live region --}}
        <div aria-live="polite" aria-atomic="true" class="sr-only" x-text="announceMessage"></div>

        <div
            x-bind="sortableContainer"
            class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 sm:gap-6"
        >
            @foreach($widgets as $index => $widget)
                <div
                    x-bind="sortableItem({{ $index }})"
                    data-widget-id="{{ $widget['id'] }}"
                    wire:key="widget-{{ $widget['id'] }}"
                    class="relative group {{ !($widget['visible'] ?? true) ? 'opacity-50' : '' }}"
                >
                    <x-card class="h-full {{ $editMode ? 'ring-1 ring-base-300' : '' }}">
                        {{-- Edit Mode Controls --}}
                        @if($editMode)
                            <div class="flex items-center justify-between gap-2 mb-3 pb-3 border-b border-base-300">
                                {{-- Drag Handle --}}
                                <div class="flex items-center gap-2 min-w-0 cursor-grab active:cursor-grabbing" title="Drag to reorder">
                                    <x-icon name="o-bars-3" class="w-5 h-5 text-base-content/50 flex-shrink-0" />
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <x-icon name="{{ $this->getWidgetTypeIcon($widget['type']) }}" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                                            <span class="text-sm font-medium truncate">{{ $this->getWidgetTypeLabel($widget['type']) }}</span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Widget Action Buttons --}}
                                <div class="flex items-center gap-1 flex-shrink-0">
                                    {{-- Visibility Toggle --}}
                                    <button
                                        wire:click="toggleVisibility('{{ $widget['id'] }}')"
                                        class="btn btn-ghost btn-xs btn-square"
                                        title="{{ ($widget['visible'] ?? true) ? 'Hide widget' : 'Show widget' }}"
                                    >
                                        <x-icon
                                            name="{{ ($widget['visible'] ?? true) ? 'o-eye' : 'o-eye-slash' }}"
                                            class="w-4 h-4"
                                        />
                                    </button>

                                    {{-- Configure --}}
                                    <button
                                        wire:click="configureWidget('{{ $widget['id'] }}')"
                                        class="btn btn-ghost btn-xs btn-square"
                                        title="Configure widget"
                                    >
                                        <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                                    </button>

                                    {{-- Remove --}}
                                    <button
                                        wire:click="confirmRemoveWidget('{{ $widget['id'] }}')"
                                        class="btn btn-ghost btn-xs btn-square text-error hover:bg-error/10"
                                        title="Remove widget"
                                    >
                                        <x-icon name="o-trash" class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        @endif

                        {{-- Widget Content Preview --}}
                        <div class="text-center text-base-content/60">
                            <x-icon
                                name="{{ $this->getWidgetTypeIcon($widget['type']) }}"
                                class="w-8 h-8 mx-auto mb-2"
                            />
                            <p class="text-sm font-medium">{{ $this->getWidgetTypeLabel($widget['type']) }}</p>
                            @if(isset($widget['config']) && is_array($widget['config']))
                                <p class="text-xs text-base-content/40 mt-1">
                                    @foreach(array_slice($widget['config'], 0, 2) as $key => $value)
                                        <span>{{ ucfirst(str_replace('_', ' ', $key)) }}: {{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</span>
                                        @if(!$loop->last)<br>@endif
                                    @endforeach
                                </p>
                            @endif
                        </div>
                    </x-card>
                </div>
            @endforeach
        </div>

        {{-- Add Widget Button --}}
        <div class="mt-4 sm:mt-6">
            <button
                wire:click="openWidgetPicker"
                class="w-full border-2 border-dashed border-base-300 rounded-lg p-6 text-center text-base-content/50 hover:border-primary hover:text-primary transition-colors"
            >
                <x-icon name="o-plus" class="w-8 h-8 mx-auto mb-2" />
                <p class="text-sm font-medium">Add Widget</p>
                <p class="text-xs">{{ $widgets->count() }} / {{ config('dashboard.max_widgets_per_dashboard', 20) }} widgets</p>
            </button>
        </div>

        {{-- Empty State (Edit Mode) --}}
        @if($widgets->isEmpty())
            <div class="text-center py-12">
                <x-icon name="o-squares-2x2" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                <p class="text-lg font-medium text-base-content mb-2">No widgets configured</p>
                <p class="text-sm text-base-content/60 mb-6">Click the button above to add your first widget</p>
            </div>
        @endif
    </div>
    @endif

    {{-- Delete Widget Confirmation Modal --}}
    <x-modal wire:model="showDeleteConfirmation" title="Remove Widget" class="backdrop-blur" persistent separator>
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="p-3 bg-error/10 rounded-lg">
                    <x-icon name="o-exclamation-triangle" class="w-6 h-6 text-error" />
                </div>
                <div>
                    <p class="font-medium mb-2">Are you sure you want to remove this widget?</p>
                    <p class="text-sm text-base-content/70">The widget will be removed from your dashboard. You can add it back later.</p>
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                class="btn-ghost"
                wire:click="cancelRemoveWidget"
            />
            <x-button
                label="Remove Widget"
                class="btn-error"
                icon="o-trash"
                wire:click="removeWidget"
                spinner="removeWidget"
            />
        </x-slot:actions>
    </x-modal>

    {{-- Widget Configurator (nested component) --}}
    <livewire:dashboard.widget-configurator wire:key="editor-configurator" />
</div>
