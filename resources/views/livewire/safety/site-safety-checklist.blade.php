<div>
    <x-slot:title>Site Safety Checklist</x-slot:title>

    <div class="p-6">
        {{-- Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between mb-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-bold">Site Safety Checklist</h1>
                @if($eventConfig)
                    <p class="text-base-content/60">{{ $eventConfig->event->name ?? '' }}</p>
                @endif
            </div>

            @if($eventConfig && $this->items->isNotEmpty())
                @php $summary = $this->completionSummary; @endphp
                <div class="flex flex-wrap gap-2">
                    <x-badge :value="$summary['completed'] . ' of ' . $summary['total'] . ' complete'" class="badge-info" />
                    <x-badge :value="$summary['required_completed'] . ' of ' . $summary['required_total'] . ' required'" class="badge-warning" />
                </div>
            @endif
        </div>

        @if(!$eventConfig)
            <x-alert icon="o-information-circle" class="alert-info">
                No event is currently selected. Please select an event to view the safety checklist.
            </x-alert>
        @elseif($this->items->isEmpty())
            <x-alert icon="o-information-circle" class="alert-info">
                No safety checklist items are applicable for this operating class.
            </x-alert>
        @else
            @php
                $checklistTypes = $this->checklistTypes;
                $allItems = $this->items;
                $canEdit = $this->canEdit;
            @endphp

            <div class="space-y-6">
                @foreach($checklistTypes as $type)
                    @php
                        $typeItems = $allItems->filter(fn ($item) => $item->checklist_type === $type);
                    @endphp

                    @if($typeItems->isNotEmpty())
                        <x-card>
                            <x-slot:title>
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-shield-check" class="w-5 h-5" />
                                    <span>{{ $type->label() }}</span>
                                    <x-badge :value="$type->bonusPoints() . ' bonus pts'" class="badge-warning badge-sm" />
                                </div>
                            </x-slot:title>

                            <div class="space-y-3">
                                @foreach($typeItems as $item)
                                    <div wire:key="item-{{ $item->id }}" class="flex flex-col gap-2 p-3 rounded-lg bg-base-200/50 border border-base-300">
                                        <div class="flex items-start gap-3">
                                            {{-- Checkbox --}}
                                            @if($canEdit)
                                                <input
                                                    type="checkbox"
                                                    class="checkbox checkbox-sm mt-0.5"
                                                    @checked($item->entry?->is_completed)
                                                    wire:click="toggleItem({{ $item->id }})"
                                                />
                                            @else
                                                <input
                                                    type="checkbox"
                                                    class="checkbox checkbox-sm mt-0.5"
                                                    @checked($item->entry?->is_completed)
                                                    disabled
                                                />
                                            @endif

                                            <div class="flex-1 min-w-0">
                                                {{-- Label --}}
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm {{ $item->entry?->is_completed ? 'line-through text-base-content/40' : '' }}">
                                                        {{ $item->label }}
                                                    </span>
                                                    @if($item->is_required)
                                                        <x-badge value="Required" class="badge-error badge-xs" />
                                                    @endif
                                                </div>

                                                {{-- Completed info --}}
                                                @if($item->entry?->is_completed)
                                                    <p class="text-xs text-base-content/50 mt-1">
                                                        Completed by {{ $item->entry->completedBy?->first_name }} {{ $item->entry->completedBy?->last_name }}
                                                        on {{ $item->entry->completed_at?->format('M j, g:i A') }}
                                                    </p>
                                                @endif

                                                {{-- Notes --}}
                                                @if($canEdit)
                                                    <input
                                                        type="text"
                                                        class="input input-xs input-bordered w-full mt-2"
                                                        placeholder="Add notes..."
                                                        value="{{ $item->entry?->notes }}"
                                                        wire:change="updateNotes({{ $item->id }}, $event.target.value)"
                                                    />
                                                @elseif($item->entry?->notes)
                                                    <p class="text-xs text-base-content/60 mt-1 italic">{{ $item->entry->notes }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </x-card>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>
