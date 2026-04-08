<div>
    {{-- Edit Toolbar Banner --}}
    @if($editMode)
        <div class="bg-info/10 border border-info/30 rounded-lg p-4 mb-4"
             x-data
             x-init="
                window.__dashboardEditUnsaved = true;
                window.onbeforeunload = (e) => {
                    if (window.__dashboardEditUnsaved) {
                        e.preventDefault();
                        return '';
                    }
                };
             "
             x-on:dashboard-saved.window="window.__dashboardEditUnsaved = false; window.onbeforeunload = null;"
             x-on:edit-mode-changed.window="if (!$event.detail.enabled) { window.__dashboardEditUnsaved = false; window.onbeforeunload = null; }"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2 text-info">
                    <x-icon name="o-pencil-square" class="w-5 h-5" />
                    <span class="font-medium text-sm">Editing dashboard</span>
                    <span class="text-xs text-base-content/60 hidden sm:inline">— drag to reorder, click controls to configure</span>
                </div>

                <div class="flex flex-wrap gap-2 flex-shrink-0">
                    <x-button
                        label="Add Widget"
                        icon="o-plus"
                        class="btn-outline btn-sm min-h-[2.75rem] sm:min-h-[1.75rem]"
                        wire:click="openWidgetPicker"
                    />
                    <x-button
                        label="Save"
                        icon="o-check"
                        class="btn-primary btn-sm min-h-[2.75rem] sm:min-h-[1.75rem]"
                        wire:click="saveLayout"
                        x-on:click="window.__dashboardEditUnsaved = false; window.onbeforeunload = null;"
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

            {{-- Inline Title/Description Editing --}}
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <x-input
                        wire:model="title"
                        placeholder="Dashboard name"
                        icon="o-pencil"
                        class="input-sm"
                        maxlength="255"
                    />
                    @error('title')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>
                <div>
                    <x-input
                        wire:model="description"
                        placeholder="Description (optional)"
                        icon="o-document-text"
                        class="input-sm"
                        maxlength="1000"
                    />
                    @error('description')
                        <span class="text-error text-xs mt-1">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>
    @endif

    {{-- Live Widget Grid (always rendered — overlays added in edit mode) --}}
    @if($widgets->isNotEmpty())
        <div
            x-data="dashboardSortable($wire, @js($this->widgetIds))"
            @widgets-reordered.window="resetOrder($event.detail.widgetIds)"
            @edit-mode-changed.window="setEnabled($event.detail.enabled)"
            x-init="setEnabled(@js($editMode))"
            wire:key="editor-sortable-{{ $dashboard->id }}"
        >
            {{-- Screen reader live region --}}
            <div aria-live="polite" aria-atomic="true" class="sr-only" x-text="announceMessage"></div>

            <ul
                x-bind="sortableContainer"
                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 min-[1800px]:grid-cols-4 grid-flow-row-dense gap-5 widget-grid"
                style="grid-auto-rows: 90px;"
            >
                @foreach($widgets as $index => $widget)
                    @php
                        $isVisible = $widget['visible'] ?? true;
                        $colSpan = $widget['col_span'] ?? 1;
                        $rowSpan = $widget['row_span'] ?? 2;
                        $spanClasses = match($colSpan) {
                            2 => 'sm:col-span-2',
                            3 => 'sm:col-span-3',
                            4 => 'sm:col-span-4',
                            default => '',
                        };
                        $rowSpanClasses = match($rowSpan) {
                            2 => 'row-span-2',
                            3 => 'row-span-3',
                            4 => 'row-span-4',
                            5 => 'row-span-5',
                            6 => 'row-span-6',
                            default => 'row-span-2',
                        };
                    @endphp
                    <li
                        class="widget-item relative animate-fade-in {{ $spanClasses }} {{ $rowSpanClasses }} {{ !$isVisible && $editMode ? 'opacity-40' : '' }} {{ !$isVisible && !$editMode ? 'hidden' : '' }} {{ $editMode ? 'ring-2 ring-base-300 rounded-lg' : '' }} list-none"
                        :tabindex="enabled ? '0' : '-1'"
                        :draggable="String(enabled)"
                        :aria-grabbed="keyboardGrabbedIndex === {{ $index }} ? 'true' : 'false'"
                        :class="sortableItemClasses({{ $index }})"
                        x-on:dragstart="dragStart($event, {{ $index }})"
                        x-on:dragover.prevent="dragOver($event, {{ $index }})"
                        x-on:dragenter.prevent="dragEnter($event, {{ $index }})"
                        x-on:dragleave="dragLeave($event, {{ $index }})"
                        x-on:drop.prevent="drop($event, {{ $index }})"
                        x-on:dragend="dragEnd()"
                        x-on:touchstart.passive="touchStart($event, {{ $index }})"
                        x-on:keydown="keyDown($event, {{ $index }})"
                        data-widget-id="{{ $widget['id'] }}"
                        wire:key="widget-{{ $widget['id'] }}"
                        x-data="{ updating: false, showSizePicker: false }"
                        x-on:widget-updating.window="if ($event.detail.widgetId === '{{ $widget['id'] }}') updating = true"
                        x-on:widget-updated.window="if ($event.detail.widgetId === '{{ $widget['id'] }}') { updating = false; $el.classList.add('widget-pulse'); setTimeout(() => $el.classList.remove('widget-pulse'), 600); }"
                    >
                        {{-- Edit Mode Overlay Controls --}}
                        <div
                            x-show="editMode"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="absolute top-0 inset-x-0 z-20 bg-base-300/85 backdrop-blur-sm rounded-t-lg border-b border-base-300 px-3 py-2"
                            x-cloak
                        >
                            <div class="flex items-center justify-between gap-2">
                                {{-- Drag Handle + Label --}}
                                <div class="flex items-center gap-2 min-w-0 cursor-grab active:cursor-grabbing">
                                    <x-icon name="o-bars-3" class="w-5 h-5 text-base-content/50 flex-shrink-0" />
                                    <span class="text-xs font-medium truncate text-base-content/80">
                                        {{ config("dashboard.widget_types.{$widget['type']}.name", ucfirst($widget['type'])) }}
                                    </span>
                                </div>

                                {{-- Action Buttons --}}
                                <div class="flex items-center gap-1 flex-shrink-0">
                                    {{-- Size Picker Toggle --}}
                                    <div class="relative">
                                        <button
                                            @click.stop="showSizePicker = !showSizePicker"
                                            class="btn btn-ghost btn-xs btn-square"
                                            title="Resize widget"
                                        >
                                            <x-icon name="o-arrows-pointing-out" class="w-4 h-4" />
                                        </button>

                                        {{-- Size Picker Popover --}}
                                        <div
                                            x-show="showSizePicker"
                                            @click.outside="showSizePicker = false"
                                            x-transition
                                            x-cloak
                                            class="absolute right-0 top-full mt-1 z-30 bg-base-100 border border-base-300 rounded-lg shadow-lg p-3 min-w-[180px]"
                                        >
                                            <p class="text-xs font-medium text-base-content/60 mb-2">Width</p>
                                            <div class="flex gap-1 mb-3">
                                                @foreach([1, 2, 3, 4] as $c)
                                                    <button
                                                        wire:click="resizeWidget('{{ $widget['id'] }}', {{ $c }}, {{ $rowSpan }})"
                                                        @click="showSizePicker = false"
                                                        class="btn btn-xs flex-1 {{ $colSpan === $c ? 'btn-primary' : 'btn-ghost' }}"
                                                    >
                                                        {{ $c }} col{{ $c > 1 ? 's' : '' }}
                                                    </button>
                                                @endforeach
                                            </div>
                                            <p class="text-xs font-medium text-base-content/60 mb-2">Height</p>
                                            <div class="flex gap-1">
                                                @foreach([['Short', 2], ['Medium', 3], ['Tall', 6]] as [$label, $r])
                                                    <button
                                                        wire:click="resizeWidget('{{ $widget['id'] }}', {{ $colSpan }}, {{ $r }})"
                                                        @click="showSizePicker = false"
                                                        class="btn btn-xs flex-1 {{ $rowSpan === $r ? 'btn-primary' : 'btn-ghost' }}"
                                                    >
                                                        {{ $label }}
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Configure --}}
                                    <button
                                        wire:click="configureWidget('{{ $widget['id'] }}')"
                                        class="btn btn-ghost btn-xs btn-square"
                                        title="Configure widget"
                                    >
                                        <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                                    </button>

                                    {{-- Visibility Toggle --}}
                                    <button
                                        wire:click="toggleVisibility('{{ $widget['id'] }}')"
                                        class="btn btn-ghost btn-xs btn-square"
                                        title="{{ $isVisible ? 'Hide widget' : 'Show widget' }}"
                                    >
                                        <x-icon
                                            name="{{ $isVisible ? 'o-eye' : 'o-eye-slash' }}"
                                            class="w-4 h-4"
                                        />
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
                        </div>

                        {{-- Widget Content --}}
                        <div class="relative h-full overflow-y-auto" :class="{ 'pt-10': editMode }">
                            {{-- Loading indicator overlay --}}
                            <div
                                x-show="updating"
                                x-transition:enter="transition ease-out duration-200"
                                x-transition:enter-start="opacity-0"
                                x-transition:enter-end="opacity-100"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                class="absolute inset-0 bg-base-100/50 backdrop-blur-[2px] rounded-lg flex items-center justify-center z-10"
                                style="display: none;"
                            >
                                <span class="loading loading-spinner loading-md text-primary"></span>
                            </div>

                            {{-- Render Live Widget Component --}}
                            @switch($widget['type'])
                                @case('stat_card')
                                    <livewire:dashboard.widgets.stat-card
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="stat-{{ $widget['id'] }}"
                                    />
                                    @break

                                @case('chart')
                                    <livewire:dashboard.widgets.chart
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="chart-{{ $widget['id'] }}"
                                    />
                                    @break

                                @case('progress_bar')
                                    <livewire:dashboard.widgets.progress-bar
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="progress-{{ $widget['id'] }}"
                                    />
                                    @break

                                @case('list_widget')
                                    <livewire:dashboard.widgets.list-widget
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="list-{{ $widget['id'] }}"
                                    />
                                    @break

                                @case('timer')
                                    <livewire:dashboard.widgets.timer
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="timer-{{ $widget['id'] }}"
                                    />
                                    @break

                                @case('info_card')
                                    <livewire:dashboard.widgets.info-card
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="info-{{ $widget['id'] }}"
                                    />
                                    @break

                                @case('feed')
                                    <livewire:dashboard.widgets.feed
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="feed-{{ $widget['id'] }}"
                                    />
                                    @break

                                @case('message_traffic_score')
                                    <livewire:dashboard.widgets.message-traffic-score
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="mts-{{ $widget['id'] }}"
                                    />
                                    @break

                                @case('sections_worked')
                                    <livewire:dashboard.widgets.sections-worked
                                        :config="$widget['config']"
                                        :widget-id="$widget['id']"
                                        size="normal"
                                        wire:key="sections-{{ $widget['id'] }}"
                                    />
                                    @break

                                @default
                                    <x-card class="h-full">
                                        <div class="flex flex-col items-center justify-center py-8 text-base-content/50">
                                            <x-icon name="o-question-mark-circle" class="w-12 h-12 mb-3" />
                                            <p class="text-sm">Unknown widget type: {{ $widget['type'] }}</p>
                                        </div>
                                    </x-card>
                            @endswitch
                        </div>
                    </li>
                @endforeach

                {{-- Add Widget Card (edit mode only) --}}
                <li x-show="editMode" x-cloak class="row-span-2 list-none">
                    <button
                        wire:click="openWidgetPicker"
                        class="w-full h-full min-h-[120px] border-2 border-dashed border-base-300 rounded-lg p-6 text-center text-base-content/50 hover:border-primary hover:text-primary transition-colors"
                    >
                        <x-icon name="o-plus" class="w-8 h-8 mx-auto mb-2" />
                        <p class="text-sm font-medium">Add Widget</p>
                    </button>
                </li>
            </ul>
        </div>
    @else
        {{-- Empty State --}}
        <div class="text-center py-12">
            <x-icon name="o-squares-2x2" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
            <p class="text-lg font-medium text-base-content mb-2">No widgets configured</p>
            <p class="text-sm text-base-content/60 mb-6">Click "Add Widget" above to add your first widget</p>
            @if(!$editMode)
                <x-button
                    label="Edit Layout"
                    icon="o-pencil-square"
                    class="btn-primary"
                    wire:click="toggleEditMode"
                />
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
