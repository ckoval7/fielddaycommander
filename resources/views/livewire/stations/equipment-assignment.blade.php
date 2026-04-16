<div class="space-y-4" x-data="{
    announceMessage: '',
    announce(message) {
        this.announceMessage = message;
        setTimeout(() => { this.announceMessage = ''; }, 3000);
    }
}">
    {{-- Screen Reader Announcements --}}
    <output class="sr-only" aria-live="polite" aria-atomic="true" x-text="announceMessage"></output>

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-start lg:items-center sm:justify-between gap-4">
        <div class="min-w-0">
            <h3 class="text-lg font-semibold">Equipment Assignment</h3>
            <p class="text-xs sm:text-sm text-base-content/70 truncate">
                Assign additional equipment to {{ $this->stationModel?->name ?? 'this station' }}
            </p>
        </div>
        @if($this->assignedCount > 0)
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4 text-xs sm:text-sm flex-shrink-0">
                <span class="flex items-center gap-1.5">
                    <x-icon name="phosphor-cube" class="w-4 h-4 flex-shrink-0" />
                    <span class="font-medium">{{ $this->assignedCount }}</span> <span class="hidden sm:inline">items</span>
                </span>
                @if($this->assignedTotalValue > 0)
                    <span class="flex items-center gap-1.5">
                        <x-icon name="phosphor-currency-dollar" class="w-4 h-4 flex-shrink-0" />
                        <span class="font-medium">${{ number_format($this->assignedTotalValue, 2) }}</span>
                    </span>
                @endif
            </div>
        @endif
    </div>

    @if($this->hasCommittedEquipmentDuringSession)
        <x-alert
            title="Equipment Not Delivered"
            description="This station is operating but some equipment hasn't been marked as delivered."
            icon="phosphor-warning"
            class="alert-warning"
        />
    @endif

    {{-- Two Column Layout (Single on mobile, two on lg+) --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
        {{-- Left Column: Assigned Equipment --}}
        <section
            class="h-fit transition-all duration-200"
            x-data="{
                dragOver: false,
                isValidDrop: true
            }"
            x-on:dragover.prevent="
                dragOver = true;
                isValidDrop = true;
            "
            x-on:dragleave.prevent="dragOver = false"
            x-on:drop.prevent="
                dragOver = false;
                const data = JSON.parse($event.dataTransfer.getData('application/json'));
                $wire.handleDrop(data.equipmentId, data.fromCatalog);
            "
            x-bind:class="{
                'ring-2 ring-success ring-offset-2 bg-success/5': dragOver && isValidDrop,
                'ring-2 ring-error ring-offset-2 bg-error/5': dragOver && !isValidDrop
            }"
            aria-label="Assigned equipment drop zone"
            aria-live="polite"
        >
        <x-card>
            <x-slot:title>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <x-icon name="phosphor-check-circle" class="w-5 h-5 text-success flex-shrink-0" />
                        <span>Assigned to Station</span>
                    </div>
                    <div class="text-xs text-base-content/50 font-normal hidden sm:block">
                        Drag equipment here or use keyboard (Space/Arrows/Escape)
                    </div>
                </div>
            </x-slot:title>

            @if($this->stationModel?->primaryRadio)
                {{-- Primary Radio Section (Read-only) --}}
                <div class="mb-4">
                    <div class="text-xs font-semibold text-base-content/50 uppercase tracking-wider mb-2">
                        Primary Radio
                    </div>
                    <div class="bg-base-200 rounded-lg p-2 sm:p-3 border border-base-300">
                        <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                            <div class="p-2 rounded-lg bg-primary/10 flex-shrink-0">
                                <x-icon name="phosphor-radio" class="w-5 h-5 text-primary" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium truncate text-sm sm:text-base">
                                    {{ $this->stationModel->primaryRadio->make }} {{ $this->stationModel->primaryRadio->model }}
                                </div>
                                <div class="flex flex-wrap items-center gap-2 mt-1 text-xs sm:text-sm text-base-content/70">
                                    <span>{{ $this->getOwnerDisplay($this->stationModel->primaryRadio) }}</span>
                                    @if($this->stationModel->primaryRadio->power_output_watts)
                                        <x-badge value="{{ $this->stationModel->primaryRadio->power_output_watts }}W" class="badge-xs sm:badge-sm badge-ghost" />
                                    @endif
                                </div>
                            </div>
                            <x-button
                                icon="phosphor-eye"
                                class="btn-ghost btn-sm btn-circle min-h-[2.75rem] sm:min-h-[1.75rem] flex-shrink-0"
                                wire:click="showDetails({{ $this->stationModel->primaryRadio->id }})"
                                title="View details"
                            />
                        </div>
                        <div class="mt-2 text-xs text-base-content/50 italic">
                            Primary radio is selected in the main station form
                        </div>
                    </div>
                </div>
            @endif

            {{-- Additional Equipment by Type --}}
            @forelse($this->assignedEquipmentByType as $type => $commitments)
                <div class="mb-4 last:mb-0">
                    <div class="text-xs font-semibold text-base-content/50 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                        <x-icon :name="$this->getTypeIcon($type)" class="w-4 h-4 flex-shrink-0" />
                        <span class="truncate">{{ $this->getTypeName($type) }}</span>
                        <x-badge value="{{ $commitments->count() }}" class="badge-xs badge-neutral flex-shrink-0" />
                    </div>
                    <div class="space-y-2">
                        @foreach($commitments as $commitment)
                            <div
                                class="bg-base-100 border border-base-300 rounded-lg p-2 sm:p-3 hover:shadow-sm transition-all duration-200 animate-fade-in"
                                wire:key="assigned-{{ $commitment->id }}"
                                x-data="{ justAdded: false }"
                                x-init="
                                    justAdded = true;
                                    $el.classList.add('scale-105');
                                    setTimeout(() => {
                                        $el.classList.remove('scale-105');
                                        justAdded = false;
                                    }, 300);
                                "
                            >
                                <div class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-3">
                                    <div class="p-2 rounded-lg bg-base-200 flex-shrink-0">
                                        <x-icon :name="$this->getTypeIcon($commitment->equipment->type)" class="w-5 h-5" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium truncate text-sm sm:text-base">
                                            {{ $commitment->equipment->make }} {{ $commitment->equipment->model }}
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2 mt-1">
                                            <span class="text-xs sm:text-sm text-base-content/70">
                                                {{ $this->getOwnerDisplay($commitment->equipment) }}
                                            </span>
                                            <x-badge
                                                value="{{ ucfirst($commitment->status) }}"
                                                class="badge-xs sm:badge-sm {{ $this->getStatusBadgeClass($commitment->status) }}"
                                            />
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1 flex-shrink-0">
                                        <x-button
                                            icon="phosphor-eye"
                                            class="btn-ghost btn-sm btn-circle min-h-[2.75rem] sm:min-h-[1.75rem]"
                                            wire:click="showDetails({{ $commitment->equipment->id }})"
                                            title="View details"
                                        />
                                        @if($this->canManage)
                                            <x-button
                                                icon="phosphor-x"
                                                class="btn-ghost btn-sm btn-circle text-error min-h-[2.75rem] sm:min-h-[1.75rem]"
                                                wire:click="requestUnassign({{ $commitment->equipment->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="requestUnassign"
                                                title="Unassign"
                                            />
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                @if(!$this->stationModel?->primaryRadio)
                    <output class="text-center py-6 sm:py-8 block" aria-label="No equipment assigned">
                        <x-icon name="phosphor-cube" class="w-10 sm:w-12 h-10 sm:h-12 mx-auto text-base-content/30" />
                        <p class="mt-2 text-xs sm:text-base text-base-content/70">No equipment assigned</p>
                        <p class="text-xs sm:text-sm text-base-content/50 mt-1">
                            Browse available equipment below, then drag & drop to assign
                        </p>
                        <p class="text-xs text-base-content/40 mt-2 hidden sm:block">
                            Keyboard: Tab to equipment, Space to pick up, Space again to drop, Escape to cancel
                        </p>
                    </output>
                @else
                    <output class="text-center py-4 text-xs sm:text-sm text-base-content/50 block">
                        No additional equipment assigned. Browse available equipment below.
                    </output>
                @endif
            @endforelse
        </x-card>
        </section>

        {{-- Right Column: Available Equipment --}}
        <x-card>
            <x-slot:title>
                <div class="flex items-center gap-2">
                    <x-icon name="phosphor-squares-four" class="w-5 h-5 text-info flex-shrink-0" />
                    <span class="truncate">Available Equipment</span>
                </div>
            </x-slot:title>

            {{-- Filters --}}
            <div class="mb-4 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <x-input
                        wire:model.live.debounce.300ms="searchQuery"
                        placeholder="Search make/model..."
                        icon="phosphor-magnifying-glass"
                        clearable
                    />
                    <x-select
                        wire:model.live="typeFilter"
                        :options="$this->availableTypes"
                        option-value="id"
                        option-label="name"
                        placeholder="All Types"
                        icon="phosphor-funnel"
                    />
                </div>
                <div class="flex flex-col sm:flex-row sm:items-center sm:flex-wrap gap-2">
                    <x-select
                        wire:model.live="ownerFilter"
                        :options="$this->ownerOptions"
                        option-value="id"
                        option-label="name"
                        class="select-sm w-full sm:w-auto sm:min-w-32"
                    />
                    @if($searchQuery || $typeFilter || $ownerFilter !== 'all' || $bandFilter)
                        <x-button
                            label="Clear"
                            icon="phosphor-x"
                            class="btn-ghost btn-sm w-full sm:w-auto"
                            wire:click="clearFilters"
                        />
                    @endif
                </div>
            </div>

            {{-- Tabs: Committed to Event / All Catalog --}}
            <x-tabs wire:model="availableTab">
                <x-tab name="committed" label="Committed to Event" icon="phosphor-calendar">
                    <div class="mt-4 space-y-2 max-h-96 overflow-y-auto pr-2">
                        @forelse($this->eventCommittedEquipment as $commitment)
                            <div
                                class="bg-base-100 border border-base-300 rounded-lg p-2 sm:p-3 hover:shadow-md transition-all duration-200 cursor-grab active:cursor-grabbing focus:ring-2 focus:ring-primary focus:outline-none"
                                wire:key="committed-{{ $commitment->id }}"
                                draggable="true"
                                tabindex="0"
                                aria-label="Drag {{ $commitment->equipment->make }} {{ $commitment->equipment->model }} to assign to station. Press Space to pick up, Arrow keys to navigate, Space to drop, Escape to cancel."
                                onkeydown="/* handled by Alpine */"
                                @keydown="handleKeydown($event)"
                                x-data="{
                                    isDragging: false,
                                    keyboardDrag: false,
                                    handleKeydown(e) {
                                        if (e.key === ' ' && !this.keyboardDrag) {
                                            e.preventDefault();
                                            this.startKeyboardDrag();
                                        } else if (e.key === ' ' && this.keyboardDrag) {
                                            e.preventDefault();
                                            this.finishKeyboardDrag();
                                        } else if (e.key === 'Escape' && this.keyboardDrag) {
                                            e.preventDefault();
                                            this.cancelKeyboardDrag();
                                        }
                                    },
                                    startKeyboardDrag() {
                                        this.keyboardDrag = true;
                                        this.$el.setAttribute('aria-grabbed', 'true');
                                        this.$el.classList.add('ring-2', 'ring-primary', 'scale-105', 'shadow-lg');
                                        this.$root.announce('Equipment picked up. Press Space to drop in assigned zone, or Escape to cancel.');
                                    },
                                    finishKeyboardDrag() {
                                        this.keyboardDrag = false;
                                        this.$el.setAttribute('aria-grabbed', 'false');
                                        this.$el.classList.remove('ring-2', 'ring-primary', 'scale-105', 'shadow-lg');
                                        $wire.handleDrop({{ $commitment->equipment->id }}, false);
                                        this.$root.announce('Equipment assigned to station.');
                                    },
                                    cancelKeyboardDrag() {
                                        this.keyboardDrag = false;
                                        this.$el.setAttribute('aria-grabbed', 'false');
                                        this.$el.classList.remove('ring-2', 'ring-primary', 'scale-105', 'shadow-lg');
                                        this.$root.announce('Drag cancelled.');
                                    }
                                }"
                                @keydown="handleKeydown($event)"
                                @keydown.enter.prevent="handleKeydown({key: ' ', preventDefault: () => {}})"
                                x-on:dragstart="
                                    isDragging = true;
                                    $event.dataTransfer.setData('application/json', JSON.stringify({
                                        equipmentId: {{ $commitment->equipment->id }},
                                        fromCatalog: false
                                    }));
                                    $event.target.classList.add('opacity-50', 'rotate-2');
                                    $event.dataTransfer.effectAllowed = 'move';
                                "
                                x-on:dragend="
                                    isDragging = false;
                                    $event.target.classList.remove('opacity-50', 'rotate-2');
                                    setTimeout(() => {
                                        $event.target.classList.add('scale-105');
                                        setTimeout(() => {
                                            $event.target.classList.remove('scale-105');
                                        }, 150);
                                    }, 50);
                                "
                                aria-grabbed="false"
                            >
                                <div class="flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-3">
                                    @if($commitment->equipment->photo_path)
                                        <img
                                            src="{{ Storage::url($commitment->equipment->photo_path) }}"
                                            alt="{{ $commitment->equipment->make }} {{ $commitment->equipment->model }}"
                                            class="w-12 h-12 rounded-lg object-cover flex-shrink-0"
                                        />
                                    @else
                                        <div class="p-2 rounded-lg bg-base-200 flex-shrink-0">
                                            <x-icon :name="$this->getTypeIcon($commitment->equipment->type)" class="w-6 h-6" />
                                        </div>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium truncate text-sm sm:text-base">
                                            {{ $commitment->equipment->make }} {{ $commitment->equipment->model }}
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2 mt-1 text-xs sm:text-sm">
                                            <x-badge value="{{ ucfirst($commitment->equipment->type) }}" class="badge-xs sm:badge-sm badge-ghost" />
                                            <span class="text-base-content/70">
                                                {{ $this->getOwnerDisplay($commitment->equipment) }}
                                            </span>
                                        </div>
                                        @if($commitment->equipment->bands->count() > 0)
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                @foreach($commitment->equipment->bands->take(3) as $band)
                                                    <x-badge value="{{ $band->name }}" class="badge-xs badge-primary badge-outline" />
                                                @endforeach
                                                @if($commitment->equipment->bands->count() > 3)
                                                    <x-badge value="+{{ $commitment->equipment->bands->count() - 3 }}" class="badge-xs" />
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                    @if($this->canManage)
                                        <x-button
                                            label="Assign"
                                            icon="phosphor-plus"
                                            class="btn-primary btn-sm w-full sm:w-auto min-h-[2.75rem] sm:min-h-[1.75rem] flex-shrink-0"
                                            wire:click="assignEquipment({{ $commitment->equipment->id }}, false)"
                                            wire:loading.attr="disabled"
                                            wire:target="assignEquipment"
                                            spinner
                                        />
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-6 sm:py-8">
                                <x-icon name="phosphor-tray" class="w-8 sm:w-10 h-8 sm:h-10 mx-auto text-base-content/30" />
                                <p class="mt-2 text-xs sm:text-sm text-base-content/50">
                                    @if($searchQuery || $typeFilter || $ownerFilter !== 'all')
                                        No equipment matches your filters
                                    @else
                                        No uncommitted equipment available for this event
                                    @endif
                                </p>
                            </div>
                        @endforelse
                    </div>
                </x-tab>

                <x-tab name="catalog" label="All Equipment Catalog" icon="phosphor-archive">
                    <div class="mt-4 space-y-2 max-h-96 overflow-y-auto pr-2">
                        @forelse($this->catalogEquipment as $equipment)
                            <div
                                class="bg-base-100 border border-base-300 rounded-lg p-2 sm:p-3 hover:shadow-md transition-all duration-200 cursor-grab active:cursor-grabbing focus:ring-2 focus:ring-primary focus:outline-none"
                                wire:key="catalog-{{ $equipment->id }}"
                                draggable="true"
                                tabindex="0"
                                aria-label="Drag {{ $equipment->make }} {{ $equipment->model }} to commit and assign to station. Press Space to pick up, Arrow keys to navigate, Space to drop, Escape to cancel."
                                onkeydown="/* handled by Alpine */"
                                @keydown="handleKeydown($event)"
                                x-data="{
                                    isDragging: false,
                                    keyboardDrag: false,
                                    handleKeydown(e) {
                                        if (e.key === ' ' && !this.keyboardDrag) {
                                            e.preventDefault();
                                            this.startKeyboardDrag();
                                        } else if (e.key === ' ' && this.keyboardDrag) {
                                            e.preventDefault();
                                            this.finishKeyboardDrag();
                                        } else if (e.key === 'Escape' && this.keyboardDrag) {
                                            e.preventDefault();
                                            this.cancelKeyboardDrag();
                                        }
                                    },
                                    startKeyboardDrag() {
                                        this.keyboardDrag = true;
                                        this.$el.setAttribute('aria-grabbed', 'true');
                                        this.$el.classList.add('ring-2', 'ring-primary', 'scale-105', 'shadow-lg');
                                        this.$root.announce('Equipment picked up. Press Space to commit and assign to station, or Escape to cancel.');
                                    },
                                    finishKeyboardDrag() {
                                        this.keyboardDrag = false;
                                        this.$el.setAttribute('aria-grabbed', 'false');
                                        this.$el.classList.remove('ring-2', 'ring-primary', 'scale-105', 'shadow-lg');
                                        $wire.handleDrop({{ $equipment->id }}, true);
                                        this.$root.announce('Equipment committed and assigned to station.');
                                    },
                                    cancelKeyboardDrag() {
                                        this.keyboardDrag = false;
                                        this.$el.setAttribute('aria-grabbed', 'false');
                                        this.$el.classList.remove('ring-2', 'ring-primary', 'scale-105', 'shadow-lg');
                                        this.$root.announce('Drag cancelled.');
                                    }
                                }"
                                @keydown="handleKeydown($event)"
                                @keydown.enter.prevent="handleKeydown({key: ' ', preventDefault: () => {}})"
                                x-on:dragstart="
                                    isDragging = true;
                                    $event.dataTransfer.setData('application/json', JSON.stringify({
                                        equipmentId: {{ $equipment->id }},
                                        fromCatalog: true
                                    }));
                                    $event.target.classList.add('opacity-50', 'rotate-2');
                                    $event.dataTransfer.effectAllowed = 'move';
                                "
                                x-on:dragend="
                                    isDragging = false;
                                    $event.target.classList.remove('opacity-50', 'rotate-2');
                                    setTimeout(() => {
                                        $event.target.classList.add('scale-105');
                                        setTimeout(() => {
                                            $event.target.classList.remove('scale-105');
                                        }, 150);
                                    }, 50);
                                "
                                aria-grabbed="false"
                            >
                                <div class="flex flex-col lg:flex-row lg:items-start gap-2 lg:gap-3">
                                    @if($equipment->photo_path)
                                        <img
                                            src="{{ Storage::url($equipment->photo_path) }}"
                                            alt="{{ $equipment->make }} {{ $equipment->model }}"
                                            class="w-12 h-12 rounded-lg object-cover flex-shrink-0"
                                        />
                                    @else
                                        <div class="p-2 rounded-lg bg-base-200 flex-shrink-0">
                                            <x-icon :name="$this->getTypeIcon($equipment->type)" class="w-6 h-6" />
                                        </div>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium truncate text-sm lg:text-base">
                                            {{ $equipment->make }} {{ $equipment->model }}
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2 mt-1 text-xs lg:text-sm">
                                            <x-badge value="{{ ucfirst($equipment->type) }}" class="badge-xs lg:badge-sm badge-ghost" />
                                            <span class="text-base-content/70">
                                                {{ $this->getOwnerDisplay($equipment) }}
                                            </span>
                                            @if($equipment->power_output_watts)
                                                <x-badge value="{{ $equipment->power_output_watts }}W" class="badge-xs lg:badge-sm badge-warning badge-outline" />
                                            @endif
                                        </div>
                                        @if($equipment->bands->count() > 0)
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                @foreach($equipment->bands->take(3) as $band)
                                                    <x-badge value="{{ $band->name }}" class="badge-xs badge-primary badge-outline" />
                                                @endforeach
                                                @if($equipment->bands->count() > 3)
                                                    <x-badge value="+{{ $equipment->bands->count() - 3 }}" class="badge-xs" />
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                    @if($this->canManage)
                                        <x-button
                                            label="Commit & Assign"
                                            icon="phosphor-plus"
                                            class="btn-success btn-sm w-full lg:w-auto min-h-[2.75rem] lg:min-h-[1.75rem] flex-shrink-0"
                                            wire:click="assignEquipment({{ $equipment->id }}, true)"
                                            wire:loading.attr="disabled"
                                            wire:target="assignEquipment"
                                            spinner
                                        />
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-6 sm:py-8">
                                <x-icon name="phosphor-tray" class="w-8 sm:w-10 h-8 sm:h-10 mx-auto text-base-content/30" />
                                <p class="mt-2 text-xs sm:text-sm text-base-content/50">
                                    @if($searchQuery || $typeFilter || $ownerFilter !== 'all')
                                        No equipment matches your filters
                                    @else
                                        No equipment available in the catalog
                                    @endif
                                </p>
                            </div>
                        @endforelse
                    </div>
                </x-tab>
            </x-tabs>
        </x-card>
    </div>

    {{-- Conflict Resolution Modal --}}
    <x-modal wire:model="showConflictModal" title="Equipment Already Assigned" persistent>
        @if($conflictData)
            <div class="space-y-4">
                <x-alert icon="phosphor-warning" class="alert-warning">
                    This {{ $conflictData['equipment_type'] }} is currently assigned to another station.
                </x-alert>

                <div class="bg-base-200 rounded-lg p-4">
                    <div class="font-medium text-base sm:text-lg">
                        {{ $conflictData['equipment_make'] }} {{ $conflictData['equipment_model'] }}
                    </div>
                    <div class="mt-2 space-y-1 text-xs sm:text-sm text-base-content/70">
                        <div class="flex flex-col sm:flex-row sm:justify-between gap-1">
                            <span>Current Station:</span>
                            <span class="font-medium text-base-content break-words">{{ $conflictData['current_station_name'] }}</span>
                        </div>
                        <div class="flex flex-col sm:flex-row sm:justify-between gap-1">
                            <span>Assigned by:</span>
                            <span class="font-medium text-base-content break-words">{{ $conflictData['assigned_by'] }}</span>
                        </div>
                        @if($conflictData['assigned_at'])
                            <div class="flex flex-col sm:flex-row sm:justify-between gap-1">
                                <span>Assigned on:</span>
                                <span>{{ $conflictData['assigned_at'] }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <p class="text-xs sm:text-sm text-base-content/70">
                    Would you like to unassign this equipment from
                    <span class="font-medium">{{ $conflictData['current_station_name'] }}</span>
                    and assign it to this station instead?
                </p>
            </div>
        @endif

        <x-slot:actions>
            <x-button
                label="Cancel"
                wire:click="cancelConflict"
                class="w-full sm:w-auto"
            />
            <x-button
                label="Reassign to This Station"
                class="btn-warning w-full sm:w-auto"
                icon="phosphor-arrow-clockwise"
                wire:click="confirmReassignment"
                spinner="confirmReassignment"
            />
        </x-slot:actions>
    </x-modal>

    {{-- Unassign Confirmation Modal --}}
    <x-modal wire:model="showUnassignConfirmModal" title="Confirm Unassign" persistent>
        <x-alert icon="phosphor-warning" class="alert-warning">
            This station has an active operating session. Unassigning equipment while the station is in use may cause issues.
        </x-alert>
        <p class="mt-4 text-xs sm:text-sm text-base-content/70">
            The equipment will remain committed to this event but will no longer be assigned to this station.
        </p>

        <x-slot:actions>
            <x-button
                label="Cancel"
                wire:click="cancelUnassign"
                class="w-full sm:w-auto"
            />
            <x-button
                label="Unassign Equipment"
                class="btn-error w-full sm:w-auto"
                icon="phosphor-x"
                wire:click="unassignEquipment({{ $unassignEquipmentId }})"
                spinner="unassignEquipment"
            />
        </x-slot:actions>
    </x-modal>

    {{-- Warning Confirmation Modal --}}
    <x-modal wire:model="showWarningModal" title="Assignment Warnings" persistent>
        <div class="space-y-3">
            <p class="text-sm text-base-content/70">
                The following issues were detected. Would you like to proceed anyway?
            </p>

            @foreach($warningMessages as $warning)
                <x-alert icon="phosphor-warning" class="alert-warning">
                    <div>
                        <div class="font-semibold">{{ $warning['title'] }}</div>
                        <div class="text-sm">{{ $warning['message'] }}</div>
                    </div>
                </x-alert>
            @endforeach
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                wire:click="cancelWarningAssignment"
                class="w-full sm:w-auto"
            />
            <x-button
                label="Assign Anyway"
                class="btn-warning w-full sm:w-auto"
                icon="phosphor-check"
                wire:click="confirmWarningAssignment"
                spinner="confirmWarningAssignment"
            />
        </x-slot:actions>
    </x-modal>

    {{-- Equipment Details Modal --}}
    <x-modal wire:model="showDetailsModal" title="Equipment Details" class="backdrop-blur">
        @if($this->detailsEquipment)
            <div class="space-y-4">
                {{-- Photo --}}
                @if($this->detailsEquipment->photo_path)
                    <div class="flex justify-center">
                        <img
                            src="{{ Storage::url($this->detailsEquipment->photo_path) }}"
                            alt="{{ $this->detailsEquipment->make }} {{ $this->detailsEquipment->model }}"
                            class="max-h-40 sm:max-h-48 rounded-lg object-contain"
                        />
                    </div>
                @endif

                {{-- Basic Info --}}
                <div class="text-center">
                    <h4 class="text-base sm:text-lg font-semibold">
                        {{ $this->detailsEquipment->make }} {{ $this->detailsEquipment->model }}
                    </h4>
                    <x-badge value="{{ ucfirst($this->detailsEquipment->type) }}" class="badge-md sm:badge-lg badge-primary" />
                </div>

                {{-- Details Grid --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs sm:text-sm">
                    <div>
                        <span class="text-base-content/50">Owner</span>
                        <div class="font-medium break-words">{{ $this->getOwnerDisplay($this->detailsEquipment) }}</div>
                    </div>
                    @if($this->detailsEquipment->power_output_watts)
                        <div>
                            <span class="text-base-content/50">Power Output</span>
                            <div class="font-medium">{{ $this->detailsEquipment->power_output_watts }}W</div>
                        </div>
                    @endif
                    @if($this->detailsEquipment->serial_number)
                        <div>
                            <span class="text-base-content/50">Serial Number</span>
                            <div class="font-medium font-mono text-xs break-all">{{ $this->detailsEquipment->serial_number }}</div>
                        </div>
                    @endif
                    @if($this->detailsEquipment->value_usd)
                        <div>
                            <span class="text-base-content/50">Value</span>
                            <div class="font-medium">${{ number_format($this->detailsEquipment->value_usd, 2) }}</div>
                        </div>
                    @endif
                </div>

                {{-- Bands --}}
                @if($this->detailsEquipment->bands->count() > 0)
                    <div>
                        <span class="text-xs sm:text-sm text-base-content/50">Supported Bands</span>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($this->detailsEquipment->bands as $band)
                                <x-badge value="{{ $band->name }}" class="badge-xs sm:badge-sm badge-primary badge-outline" />
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Description --}}
                @if($this->detailsEquipment->description)
                    <div>
                        <span class="text-xs sm:text-sm text-base-content/50">Description</span>
                        <p class="mt-1 text-xs sm:text-sm">{{ $this->detailsEquipment->description }}</p>
                    </div>
                @endif

                {{-- Notes --}}
                @if($this->detailsEquipment->notes)
                    <div>
                        <span class="text-xs sm:text-sm text-base-content/50">Notes</span>
                        <p class="mt-1 text-xs sm:text-sm whitespace-pre-line">{{ $this->detailsEquipment->notes }}</p>
                    </div>
                @endif
            </div>
        @endif

        <x-slot:actions>
            <x-button
                label="Close"
                wire:click="closeDetails"
                class="w-full sm:w-auto"
            />
        </x-slot:actions>
    </x-modal>

    {{-- Equipment Suggestions Banner --}}
    <div x-data="{ dismissed: false }" x-show="!dismissed" x-transition class="bg-info/10 border border-info/30 rounded-lg p-3 sm:p-4">
        <div class="flex items-start gap-3">
            <x-icon name="phosphor-lightbulb" class="w-5 h-5 text-info flex-shrink-0 mt-0.5" />
            <div class="flex-1 min-w-0">
                <div class="font-medium text-sm">Typical station equipment</div>
                <ul class="mt-1 text-xs sm:text-sm text-base-content/70 columns-2 gap-x-6 list-disc list-inside">
                    <li>Power Supply</li>
                    <li>Antenna & Feedline</li>
                    <li>Mic / CW Key</li>
                    <li>Headphones</li>
                    <li>Logging Computer</li>
                    <li>Coax & Cables</li>
                </ul>
            </div>
            <button @click="dismissed = true" class="btn btn-ghost btn-xs btn-circle flex-shrink-0" aria-label="Dismiss suggestions">
                <x-icon name="phosphor-x" class="w-4 h-4" />
            </button>
        </div>
    </div>
</div>
