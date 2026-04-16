<div>
    {{-- Clone Modal --}}
    <x-modal wire:model="showModal" title="Clone Stations from Previous Event" subtitle="Copy station configurations from a past event to a new event" class="backdrop-blur" persistent>
        <form wire:submit.prevent>
            {{-- Step 1: Event Selection --}}
            <div class="mb-6">
                <x-header title="Step 1: Select Source Event" subtitle="Choose the event to clone stations from" size="text-lg" separator />

                <div class="mt-4">
                    <x-select
                        label="Source Event"
                        wire:model.live="sourceEventId"
                        :options="$sourceEvents"
                        option-value="id"
                        option-label="name"
                        placeholder="Select an event to clone from..."
                        icon="o-calendar"
                        hint="Only completed or past events with stations are shown"
                        required
                    >
                        <x-slot:prepend>
                            <x-icon name="o-arrow-path" class="w-5 h-5" />
                        </x-slot:prepend>
                    </x-select>

                    @if($sourceEventId && $sourceEvents->firstWhere('id', $sourceEventId))
                        <div class="mt-2">
                            <x-alert icon="o-information-circle" class="alert-info">
                                <span class="font-semibold">
                                    {{ $sourceEvents->firstWhere('id', $sourceEventId)['station_count'] }}
                                </span>
                                {{ str('station')->plural($sourceEvents->firstWhere('id', $sourceEventId)['station_count']) }} available
                            </x-alert>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Step 2: Station Selection (shown after event selected) --}}
            @if($sourceEventId && $availableStations->isNotEmpty())
                <div class="mb-6">
                    <x-header title="Step 2: Select Stations" subtitle="Choose which stations to clone" size="text-lg" separator />

                    <div class="mt-4 space-y-3">
                        {{-- Select All / None Buttons --}}
                        <div class="flex gap-2">
                            <x-button
                                label="Select All"
                                icon="o-check"
                                class="btn-sm btn-outline"
                                wire:click="toggleSelectAll"
                                wire:model.live="selectAll"
                                type="button"
                            />
                            <x-button
                                label="Select None"
                                icon="o-x-mark"
                                class="btn-sm btn-outline"
                                wire:click="$set('selectedStationIds', [])"
                                type="button"
                            />
                        </div>

                        {{-- Station Checkbox List --}}
                        <div class="border border-base-300 rounded-lg divide-y divide-base-300 max-h-96 overflow-y-auto">
                            @foreach($availableStations as $station)
                                <label class="flex items-start gap-3 p-3 hover:bg-base-200 cursor-pointer transition-colors" wire:key="station-{{ $station->id }}">
                                    {{-- Checkbox --}}
                                    <input
                                        type="checkbox"
                                        wire:model.live="selectedStationIds"
                                        value="{{ $station->id }}"
                                        class="checkbox checkbox-primary mt-1"
                                    />

                                    {{-- Station Info --}}
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-semibold">{{ $station->name }}</span>

                                            {{-- Badges --}}
                                            @if($station->is_gota)
                                                <x-badge value="GOTA" class="badge-primary badge-sm" icon="o-academic-cap" />
                                            @endif

                                            @if($station->is_vhf_only)
                                                <x-badge value="VHF" class="badge-info badge-sm" icon="o-signal" />
                                            @endif

                                            @if($station->is_satellite)
                                                <x-badge value="SAT" class="badge-accent badge-sm" icon="o-globe-alt" />
                                            @endif
                                        </div>

                                        {{-- Primary Radio --}}
                                        @if($station->primaryRadio)
                                            <div class="text-sm text-base-content/70 mt-1">
                                                <x-icon name="o-radio" class="w-4 h-4 inline" />
                                                {{ $station->primaryRadio->make }} {{ $station->primaryRadio->model }}
                                            </div>
                                        @endif

                                        {{-- Equipment Count --}}
                                        @if($station->additional_equipment_count > 0)
                                            <div class="text-sm text-base-content/60 mt-1">
                                                <x-icon name="o-wrench-screwdriver" class="w-4 h-4 inline" />
                                                {{ $station->additional_equipment_count }} {{ str('item')->plural($station->additional_equipment_count) }}
                                            </div>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>

                        @error('selectedStationIds')
                            <div class="text-error text-sm mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                {{-- Step 3: Clone Options --}}
                <div class="mb-6">
                    <x-header title="Step 3: Clone Options" subtitle="Configure how stations should be cloned" size="text-lg" separator />

                    <div class="mt-4 space-y-4">
                        {{-- Target Event --}}
                        <x-select
                            label="Target Event"
                            wire:model.live="targetEventId"
                            :options="$targetEvents"
                            option-value="id"
                            option-label="name"
                            placeholder="Select target event..."
                            icon="o-calendar"
                            hint="Only future or active events are shown"
                            required
                        />

                        @error('targetEventId')
                            <div class="text-error text-sm mt-1">{{ $message }}</div>
                        @enderror

                        {{-- Copy Equipment Assignments Toggle --}}
                        <x-toggle
                            label="Copy Equipment Assignments"
                            wire:model="copyEquipmentAssignments"
                            hint="Creates equipment commitments with status 'committed'. Equipment must be available."
                            right
                        />

                        {{-- Name Suffix --}}
                        <x-input
                            label="Name Suffix (Optional)"
                            wire:model="nameSuffix"
                            placeholder="e.g., ' 2025'"
                            hint="Add a suffix to station names. Leave blank to keep original names."
                            icon="o-tag"
                            maxlength="50"
                        />

                        <div class="text-sm text-base-content/70 bg-base-200 rounded-lg p-3">
                            <div class="font-medium mb-1">Example:</div>
                            <div class="flex items-center gap-2">
                                <span class="text-base-content/60">"Station 1"</span>
                                <x-icon name="o-arrow-right" class="w-4 h-4" />
                                <span class="font-medium">"Station 1{{ $nameSuffix }}"</span>
                            </div>
                        </div>
                    </div>
                </div>
            @elseif($sourceEventId && $availableStations->isEmpty())
                <div class="mb-6">
                    <x-alert icon="o-information-circle" class="alert-warning">
                        No stations found for the selected event.
                    </x-alert>
                </div>
            @endif

            {{-- Conflict Preview --}}
            @if($showConflicts && $conflictPreview)
                <div class="mb-6">
                    <x-header title="Equipment Conflicts Detected" subtitle="Some equipment cannot be assigned to the new stations" size="text-lg" separator />

                    <div class="mt-4 space-y-4">
                        {{-- Conflict Summary Alert --}}
                        <x-alert icon="o-exclamation-triangle" class="alert-warning">
                            <div class="font-medium">
                                {{ count($conflictPreview['conflicts']) }} {{ str('equipment item')->plural(count($conflictPreview['conflicts'])) }} cannot be assigned
                            </div>
                            <div class="text-sm mt-1">
                                These items are unavailable due to existing commitments or other conflicts.
                            </div>
                        </x-alert>

                        {{-- Group conflicts by station --}}
                        @php
                            $conflictsByStation = collect($conflictPreview['conflicts'])->groupBy('station_name');
                        @endphp

                        <div class="border border-base-300 rounded-lg divide-y divide-base-300 max-h-96 overflow-y-auto">
                            @foreach($conflictsByStation as $stationName => $conflicts)
                                <div class="p-4">
                                    <div class="font-semibold text-base mb-3">{{ $stationName }}</div>

                                    <div class="space-y-2">
                                        @foreach($conflicts as $conflict)
                                            <div class="flex items-start gap-3 text-sm">
                                                {{-- Equipment Icon --}}
                                                @php
                                                    $iconName = isset($conflict['equipment_type'])
                                                        ? \App\Models\Equipment::typeIcon($conflict['equipment_type'])
                                                        : 'o-cube';
                                                @endphp
                                                <x-icon :name="$iconName" class="w-5 h-5 text-warning mt-0.5 flex-shrink-0" />

                                                <div class="flex-1 min-w-0">
                                                    <div class="font-medium">{{ $conflict['make_model'] }}</div>
                                                    <div class="text-base-content/70">{{ $conflict['reason'] }}</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Conflict Actions --}}
                        <div class="flex gap-3 justify-end pt-2">
                            <x-button
                                label="Cancel Clone"
                                icon="o-x-mark"
                                class="btn-ghost"
                                wire:click="cancelConflictPreview"
                                type="button"
                            />
                            <x-button
                                label="Skip Unavailable Equipment & Continue"
                                icon="o-arrow-path"
                                class="btn-warning"
                                wire:click="continueWithSkip"
                                spinner="continueWithSkip"
                                type="button"
                            />
                        </div>
                    </div>
                </div>
            @endif

            {{-- General Errors --}}
            @error('general')
                <div class="mb-4">
                    <x-alert icon="o-exclamation-triangle" class="alert-error">
                        {{ $message }}
                    </x-alert>
                </div>
            @enderror
        </form>

        {{-- Modal Actions --}}
        <x-slot:actions>
            @if(!$showConflicts)
                <x-button
                    label="Cancel"
                    icon="o-x-mark"
                    class="btn-ghost"
                    @click="$wire.closeModal()"
                />
                <x-button
                    label="Clone Stations"
                    icon="o-arrow-path"
                    class="btn-primary"
                    wire:click="checkForConflicts"
                    :disabled="!$sourceEventId || empty($selectedStationIds) || !$targetEventId"
                    spinner="checkForConflicts"
                />
            @endif
        </x-slot:actions>
    </x-modal>

    {{-- Loading States --}}
    <div wire:loading.flex wire:target="checkForConflicts" class="fixed inset-0 bg-base-100/80 z-50 items-center justify-center">
        <div class="text-center">
            <x-loading class="loading-spinner loading-lg" />
            <div class="mt-4 text-lg font-medium">Checking for conflicts...</div>
        </div>
    </div>

    <div wire:loading.flex wire:target="proceedWithClone,continueWithSkip" class="fixed inset-0 bg-base-100/80 z-50 items-center justify-center">
        <div class="text-center">
            <x-loading class="loading-spinner loading-lg" />
            <div class="mt-4 text-lg font-medium">Cloning {{ count($selectedStationIds) }} {{ str('station')->plural(count($selectedStationIds)) }}...</div>
            <div class="text-sm text-base-content/70 mt-1">Creating equipment assignments...</div>
        </div>
    </div>
</div>
