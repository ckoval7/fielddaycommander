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
                <div class="flex flex-wrap items-center gap-2">
                    <x-badge :value="$summary['completed'] . ' of ' . $summary['total'] . ' complete'" class="badge-info" />
                    <x-badge :value="$summary['required_completed'] . ' of ' . $summary['required_total'] . ' required'" class="badge-warning" />
                    <span
                        x-data="{ show: false }"
                        x-on:autosaved.window="show = true; setTimeout(() => show = false, 1500)"
                        x-show="show"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0"
                        x-transition:enter-end="opacity-100"
                        x-transition:leave="transition ease-in duration-300"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="text-xs text-success font-medium"
                        style="display: none;"
                    >
                        <x-icon name="phosphor-check" class="w-3.5 h-3.5 inline" /> Saved
                    </span>
                </div>
            @endif
        </div>

        @if(!$eventConfig)
            <x-alert icon="phosphor-info" class="alert-info">
                No event is currently selected. Please select an event to view the safety checklist.
            </x-alert>
        @elseif($this->items->isEmpty())
            <x-alert icon="phosphor-info" class="alert-info">
                No safety checklist items are applicable for this operating class.
            </x-alert>
        @else
            @php
                $checklistTypes = $this->checklistTypes;
                $allItems = $this->items;
                $canEdit = $this->canEdit;
            @endphp

            @if(!$canEdit)
                @php
                    $requiredRoles = collect($checklistTypes)->map(fn($t) => $t->label())->join(' or ');
                @endphp
                <x-alert icon="phosphor-lock" class="alert-warning">
                    You must be signed on to a <strong>{{ $requiredRoles }}</strong> shift to check off items.
                </x-alert>
            @endif

            <div class="space-y-6">
                @foreach($checklistTypes as $type)
                    @php
                        $typeItems = $allItems->filter(fn ($item) => $item->checklist_type === $type);
                    @endphp

                    @if($typeItems->isNotEmpty())
                        <x-card>
                            <x-slot:title>
                                <div class="flex items-center gap-2">
                                    <x-icon name="phosphor-shield-check" class="w-5 h-5" />
                                    <span>{{ $type->label() }}</span>
                                    <x-badge :value="$type->bonusPoints() . ' bonus pts'" class="badge-warning badge-sm" />
                                </div>
                            </x-slot:title>

                            <div class="space-y-3">
                                @foreach($typeItems as $item)
                                    <div wire:key="item-{{ $item->id }}" x-data="{ expanded: false }" class="flex flex-col gap-2 p-3 rounded-lg bg-base-200/50 border border-base-300">
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
                                                    @if($item->help_text)
                                                        <button
                                                            type="button"
                                                            x-on:click="expanded = !expanded"
                                                            class="btn btn-ghost btn-xs btn-circle text-info hover:text-info-content hover:bg-info"
                                                            :title="expanded ? 'Hide details' : 'What does this mean?'"
                                                            :aria-expanded="expanded"
                                                        >
                                                            <x-icon name="phosphor-question" class="w-5 h-5" />
                                                        </button>
                                                    @endif
                                                </div>

                                                {{-- Help text (expandable) --}}
                                                @if($item->help_text)
                                                    <div
                                                        x-show="expanded"
                                                        x-collapse
                                                        class="mt-2 text-xs text-base-content/70 bg-info/5 border border-info/20 rounded-md px-3 py-2 leading-relaxed"
                                                    >
                                                        <x-icon name="phosphor-lightbulb" class="w-3.5 h-3.5 inline text-info mr-1 -mt-0.5" />
                                                        {{ $item->help_text }}
                                                    </div>
                                                @endif

                                                {{-- CPR/AED Trained Personnel --}}
                                                @if(str_contains($item->label, 'CPR - AED') && $this->cprAedTrainedUsers->isNotEmpty())
                                                    <div class="mt-2 text-xs text-success bg-success/5 border border-success/20 rounded-md px-3 py-2">
                                                        <x-icon name="phosphor-heart" class="w-3.5 h-3.5 inline text-success mr-1 -mt-0.5" />
                                                        <span class="font-medium">Trained personnel:</span>
                                                        {{ $this->cprAedTrainedUsers->map(fn($u) => $u->call_sign . ' (' . $u->first_name . ')')->join(', ') }}
                                                    </div>
                                                @endif

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
