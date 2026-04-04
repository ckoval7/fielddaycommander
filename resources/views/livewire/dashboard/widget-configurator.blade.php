<div>
    {{-- Widget Configuration Modal --}}
    <x-modal wire:model="showModal" title="{{ $mode === 'add' ? 'Add Widget' : 'Configure Widget' }}" persistent>
        <div class="space-y-6">
            {{-- Widget Type Selector (only for 'add' mode) --}}
            @if ($mode === 'add')
                <x-select
                    label="Widget Type"
                    wire:model.live="widgetType"
                    :options="$this->availableWidgetTypes"
                    option-value="value"
                    option-label="label"
                    placeholder="Select a widget type"
                    icon="o-cube"
                />

                @if ($widgetType)
                    @php
                        $selectedWidget = collect($this->availableWidgetTypes)->firstWhere('value', $widgetType);
                    @endphp
                    @if ($selectedWidget && $selectedWidget['description'])
                        <x-alert icon="o-information-circle" class="alert-info">
                            {{ $selectedWidget['description'] }}
                        </x-alert>
                    @endif
                @endif
            @endif

            {{-- Dynamic Form Fields Based on Schema --}}
            @if ($widgetType && count($this->getSchema()) > 0)
                <div class="space-y-4 border-t border-base-300 pt-4">
                    <h3 class="text-lg font-semibold">Configuration</h3>

                    @foreach ($this->getSchema() as $fieldName => $fieldConfig)
                        @php
                            $label = $fieldConfig['label'] ?? ucfirst(str_replace('_', ' ', $fieldName));
                            $hint = $fieldConfig['hint'] ?? $fieldConfig['description'] ?? null;
                            $placeholder = $fieldConfig['placeholder'] ?? null;
                            $required = $fieldConfig['required'] ?? false;
                        @endphp

                        @switch($fieldConfig['type'])
                            @case('select')
                                @php
                                    // Convert simple key-value array to format MaryUI expects
                                    $selectOptions = collect($fieldConfig['options'] ?? [])
                                        ->map(fn($label, $value) => ['id' => $value, 'name' => $label])
                                        ->values()
                                        ->toArray();
                                @endphp
                                <x-select
                                    :label="$label"
                                    wire:model="widgetConfig.{{ $fieldName }}"
                                    :options="$selectOptions"
                                    :hint="$hint"
                                    :placeholder="$placeholder ?? 'Select an option'"
                                />
                                @break

                            @case('number')
                                <x-input
                                    type="number"
                                    :label="$label"
                                    wire:model="widgetConfig.{{ $fieldName }}"
                                    :hint="$hint"
                                    :placeholder="$placeholder"
                                    :min="$fieldConfig['min'] ?? null"
                                    :max="$fieldConfig['max'] ?? null"
                                />
                                @break

                            @case('toggle')
                                <x-toggle
                                    :label="$label"
                                    wire:model="widgetConfig.{{ $fieldName }}"
                                    :hint="$hint"
                                />
                                @break

                            @case('checkbox')
                                <x-checkbox
                                    :label="$label"
                                    wire:model="widgetConfig.{{ $fieldName }}"
                                    :hint="$hint"
                                />
                                @break

                            @case('text')
                            @default
                                <x-input
                                    :label="$label"
                                    wire:model="widgetConfig.{{ $fieldName }}"
                                    :hint="$hint"
                                    :placeholder="$placeholder"
                                />
                                @break
                        @endswitch
                    @endforeach
                </div>
            @elseif ($mode === 'add' && !$widgetType)
                <div class="text-center py-8 text-base-content/60">
                    <x-icon name="o-arrow-up" class="w-12 h-12 mx-auto mb-2" />
                    <p>Select a widget type to configure</p>
                </div>
            @endif

            {{-- Size Picker --}}
            @if($widgetType)
                <div class="border-t border-base-300 pt-4">
                    <h3 class="text-lg font-semibold mb-3">Widget Size</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="label"><span class="label-text text-sm">Columns (1-4)</span></label>
                            <div class="flex gap-1">
                                @foreach(range(1, 4) as $c)
                                    <button
                                        type="button"
                                        wire:click="$set('colSpan', {{ $c }})"
                                        class="btn btn-sm flex-1 {{ $colSpan === $c ? 'btn-primary' : 'btn-ghost' }}"
                                    >
                                        {{ $c }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="label"><span class="label-text text-sm">Height</span></label>
                            <div class="flex gap-1">
                                @foreach([['Short', 2], ['Medium', 3], ['Tall', 6]] as [$label, $r])
                                    <button
                                        type="button"
                                        wire:click="$set('rowSpan', {{ $r }})"
                                        class="btn btn-sm flex-1 {{ $rowSpan === $r ? 'btn-primary' : 'btn-ghost' }}"
                                    >
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @php
                        $heightLabel = match($rowSpan) {
                            2 => 'Short',
                            3 => 'Medium',
                            6 => 'Tall',
                            default => $rowSpan . ' rows',
                        };
                    @endphp
                    <p class="text-xs text-base-content/60 mt-2">
                        Current: {{ $colSpan }} column{{ $colSpan > 1 ? 's' : '' }}, {{ $heightLabel }}
                    </p>
                </div>
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.cancel()" />
            <x-button
                label="Save"
                class="btn-primary"
                wire:click="save"
                :disabled="!$widgetType"
                spinner="save"
            />
        </x-slot:actions>
    </x-modal>
</div>
