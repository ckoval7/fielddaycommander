<div class="space-y-6">
    <!-- Header -->
    <x-header
        title="{{ $mode === 'create' ? 'Create Event' : ($mode === 'clone' ? 'Clone Event' : 'Edit Event') }}"
        subtitle="{{ $mode === 'create' ? 'Set up a new Field Day or contest event' : ($mode === 'clone' ? 'Create a copy of this event with updated details' : 'Update event details and configuration') }}"
        separator
        progress-indicator
    >
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="phosphor-x"
                class="btn-ghost"
                link="{{ route('events.index') }}"
                wire:navigate
            />
        </x-slot:actions>
    </x-header>

    @if($isLocked)
        <x-alert icon="phosphor-lock" class="alert-warning">
            <strong>Some fields are locked</strong> because this event has contacts or has already started.
            You can still update the event name, club name, section, end date, and guestbook settings.
        </x-alert>
    @endif

    {{-- Validation Error Summary --}}
    @if($errors->any())
        <x-alert icon="phosphor-warning" class="alert-error mb-4">
            <div>
                <div class="font-bold">Please fix the following errors:</div>
                <ul class="list-disc list-inside text-sm mt-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </x-alert>
    @endif

    <form wire:submit="save" novalidate>
        <!-- Section 1: Event Information -->
        <x-card class="mb-6">
            <x-slot:title>Event Information</x-slot:title>

            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div class="md:col-span-6">
                    <x-input
                        label="Event Name"
                        wire:model.live="name"
                        required
                        icon="phosphor-calendar"
                        hint="e.g., Field Day 2025"
                        placeholder="Field Day 2025"
                    />
                </div>

                <div class="md:col-span-2">
                    <x-select
                        label="Event Type"
                        wire:model.live="event_type_id"
                        :options="$this->eventTypes"
                        option-label="name"
                        option-value="id"
                        required
                        icon="phosphor-tag"
                        placeholder="Select event type"
                        hint="Field Day, Winter Field Day, etc."
                        :disabled="$isLocked"
                    />
                </div>

                <div class="md:col-span-2">
                    <x-select
                        label="Scoring Rules"
                        wire:model="rules_version"
                        :options="$this->rulesVersionOptions"
                        option-label="name"
                        option-value="id"
                        icon="phosphor-scales"
                        placeholder="Default ({{ $year }})"
                        hint="{{ $rulesVersionLocked ? 'Locked — event has already started.' : 'Which ARRL rule year to score by. Editable until the event starts.' }}"
                        :disabled="$rulesVersionLocked || empty($this->rulesVersionOptions)"
                    />
                </div>

                <div class="md:col-span-2">
                    <x-input
                        label="Year"
                        wire:model="year"
                        type="number"
                        min="2020"
                        max="2099"
                        readonly
                        icon="phosphor-calendar-dots"
                        hint="Auto-detected from event name"
                    />
                </div>

                <div class="md:col-span-3">
                    <x-flatpickr
                        label="Start Date & Time (UTC)"
                        wire:model.live="start_time"
                        required
                        icon="phosphor-play"
                        hint="When the event begins, in UTC"
                        :disabled="$isLocked"
                    />
                </div>

                <div class="md:col-span-3">
                    <x-flatpickr
                        label="End Date & Time (UTC)"
                        wire:model="end_time"
                        required
                        icon="phosphor-stop"
                        hint="When the event ends, in UTC"
                    />
                </div>

                @if($this->setupAllowedFrom)
                    <div class="col-span-full">
                        <div class="flex items-center gap-2 text-sm text-warning">
                            <x-icon name="phosphor-wrench" class="w-4 h-4" />
                            <span>Setup window opens: <strong>{{ $this->setupAllowedFrom }}</strong></span>
                        </div>
                    </div>
                @endif
            </div>
        </x-card>

        <!-- Section 2: Event Location -->
        <x-card class="mb-6">
            <x-slot:title>Event Location</x-slot:title>

            <div class="space-y-4">
                <p class="text-sm text-base-content/60">All location fields are optional, but each one unlocks different features. Fill in as many as apply to your site.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input
                        label="Grid Square"
                        wire:model="gridSquare"
                        icon="phosphor-map-trifold"
                        placeholder="DM79"
                        hint="Maidenhead locator (e.g. DM79 or DM79ab). Reference info for operators; not used by any automated feature yet."
                        maxlength="6"
                    />

                    <div></div>{{-- spacer --}}

                    <x-input
                        label="Latitude"
                        wire:model="latitude"
                        type="number"
                        step="0.0000001"
                        min="-90"
                        max="90"
                        icon="phosphor-map-pin"
                        placeholder="39.7392"
                        hint="Decimal degrees (e.g. 39.7392). Used for weather forecasts, NWS alerts, and guestbook proximity check-in."
                    />

                    <x-input
                        label="Longitude"
                        wire:model="longitude"
                        type="number"
                        step="0.0000001"
                        min="-180"
                        max="180"
                        icon="phosphor-map-pin"
                        placeholder="-104.9903"
                        hint="Decimal degrees (e.g. -104.9903). Used for weather forecasts, NWS alerts, and guestbook proximity check-in."
                    />

                    <x-input
                        label="City / Town"
                        wire:model="city"
                        icon="phosphor-buildings"
                        placeholder="Denver"
                        hint="Shown in auto-filled Section Manager messages as your place of origin."
                    />

                    <x-input
                        label="State / Province"
                        wire:model="state"
                        icon="phosphor-flag"
                        placeholder="CO"
                        hint="2-letter abbreviation. Shown in auto-filled Section Manager messages."
                    />

                    <x-input
                        label="Talk-in Frequency"
                        wire:model="talk_in_frequency"
                        icon="phosphor-broadcast"
                        placeholder="146.52 MHz FM"
                        hint="Frequency visitors can tune to for directions to your site."
                        maxlength="50"
                    />
                </div>
            </div>
        </x-card>

        <!-- Section 3: Station Configuration -->
        <x-card class="mb-6">
            <x-slot:title>Station Configuration</x-slot:title>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input
                    label="Station Callsign"
                    wire:model="callsign"
                    required
                    icon="phosphor-radio"
                    placeholder="W1AW"
                    hint="Primary station callsign"
                    :disabled="$isLocked"
                />

                <x-input
                    label="Club Name"
                    wire:model="club_name"
                    icon="phosphor-buildings"
                    placeholder="Amateur Radio Club"
                    hint="Optional club or organization name"
                />

                <x-select
                    label="ARRL/RAC Section"
                    wire:model="section_id"
                    :options="$this->sections"
                    option-label="name"
                    option-value="id"
                    required
                    icon="phosphor-map-trifold"
                    placeholder="Select section"
                    hint="Your ARRL or RAC section"
                />

                <x-select
                    label="Operating Class"
                    wire:model.live="operating_class_id"
                    :options="$this->operatingClasses"
                    option-label="name"
                    option-value="id"
                    required
                    icon="phosphor-flag"
                    placeholder="Select operating class"
                    hint="{{ $event_type_id ? 'Choose your operating class' : 'Select event type first' }}"
                    :disabled="!$event_type_id || $isLocked"
                />

                <x-input
                    label="Number of Transmitters"
                    wire:model="transmitter_count"
                    type="number"
                    min="1"
                    max="99"
                    required
                    icon="phosphor-cell-signal-high"
                    hint="Simultaneous transmitters (e.g., 2A = 2)"
                    :disabled="$isLocked"
                />
            </div>
        </x-card>

        <!-- Section 4: Power Configuration -->
        <x-card class="mb-6">
            <x-slot:title>
                <div class="flex items-center justify-between">
                    <span>Power Configuration</span>
                    <span class="badge badge-{{ $this->powerMultiplierColor }} badge-lg">
                        {{ $this->powerMultiplier }}× Multiplier
                    </span>
                </div>
            </x-slot:title>

            <div class="space-y-4">
                {{-- Use blur with live to update multiplier after typing, without interference during typing --}}
                <x-input
                    label="Maximum Power (Watts)"
                    wire:model.blur.live="max_power_watts"
                    type="number"
                    min="1"
                    max="{{ $this->maxPowerLimit ?? 1500 }}"
                    required
                    icon="phosphor-lightning"
                    hint="{{ $this->maxPowerLimit ? 'Class limit: ' . $this->maxPowerLimit . 'W' : 'Enter maximum transmitter power' }}"
                    :disabled="$isLocked"
                />

                <div>
                    <fieldset>
                    <legend class="block text-sm font-medium mb-3">Power Sources</legend>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="label cursor-pointer justify-start gap-3">
                                <input
                                    type="checkbox"
                                    wire:model.live="uses_commercial_power"
                                    class="checkbox checkbox-sm"
                                    @if($isLocked) disabled @endif
                                />
                                <span class="label-text">Commercial Power (Grid)</span>
                            </label>
                        </div>

                        <div class="form-control">
                            <label class="label cursor-pointer justify-start gap-3">
                                <input
                                    type="checkbox"
                                    wire:model.live="uses_generator"
                                    class="checkbox checkbox-sm"
                                    @if($isLocked) disabled @endif
                                />
                                <span class="label-text">Generator</span>
                            </label>
                        </div>

                        <div class="form-control">
                            <label class="label cursor-pointer justify-start gap-3">
                                <input
                                    type="checkbox"
                                    wire:model.live="uses_battery"
                                    class="checkbox checkbox-sm"
                                    @if($isLocked) disabled @endif
                                />
                                <span class="label-text">Battery</span>
                            </label>
                        </div>

                        <div class="form-control">
                            <label class="label cursor-pointer justify-start gap-3">
                                <input
                                    type="checkbox"
                                    wire:model.live="uses_alternate_power"
                                    class="checkbox checkbox-sm"
                                    @if($isLocked) disabled @endif
                                />
                                <span class="label-text">Alternate Power</span>
                            </label>
                            <p class="text-xs text-base-content/60 ml-9 -mt-2">
                                Examples: Solar, Wind, Water (Hydro), Methane
                            </p>
                        </div>
                    </div>
                    </fieldset>
                </div>

                <x-input
                    label="Other Power Source"
                    wire:model="uses_other_power"
                    icon="phosphor-lightbulb"
                    placeholder="Describe any other power source"
                    hint="Optional: Specify other renewable/alternative power"
                    :disabled="$isLocked"
                />

                <x-alert icon="phosphor-info" class="alert-info">
                    <div class="text-sm">
                        <strong>Power Multiplier Rules:</strong>
                        <ul class="list-disc list-inside mt-1 space-y-1">
                            <li><strong>5×:</strong> ≤5W + Natural power (battery/alternate power) + No commercial/generator</li>
                            <li><strong>2×:</strong> ≤5W + Commercial/generator OR 6-100W (any power)</li>
                            <li><strong>1×:</strong> >100W (any power)</li>
                        </ul>
                    </div>
                </x-alert>
            </div>
        </x-card>

        <!-- Section 5: GOTA Station -->
        @if($this->allowsGota || $has_gota_station)
            <x-card class="mb-6">
                <x-slot:title>GOTA Station (Get On The Air)</x-slot:title>

                <div class="space-y-4">
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start gap-3">
                            <input
                                type="checkbox"
                                wire:model.live="has_gota_station"
                                class="checkbox"
                                @if($isLocked) disabled @endif
                            />
                            <span class="label-text">Enable GOTA Station</span>
                        </label>
                        <p class="text-sm text-base-content/60 mt-1 ml-9">
                            Allow novice operators to make contacts under supervision
                        </p>
                    </div>

                    @if($has_gota_station)
                        <x-input
                            label="GOTA Callsign"
                            wire:model="gota_callsign"
                            required
                            icon="phosphor-graduation-cap"
                            placeholder="W1GOTA"
                            hint="Callsign for GOTA station (usually different from main)"
                            :disabled="$isLocked"
                        />

                        <x-alert icon="phosphor-info" class="alert-info">
                            GOTA stations must use 100W or less output power and are intended for new or inexperienced operators.
                        </x-alert>
                    @endif
                </div>
            </x-card>
        @elseif($operating_class_id && !$this->allowsGota)
            <x-alert icon="phosphor-warning" class="alert-warning mb-6">
                The selected operating class does not permit a GOTA station.
            </x-alert>
        @endif

        <!-- Section 6: Guestbook Settings -->
        <x-card class="mb-6">
            <x-slot:title>Guestbook Settings</x-slot:title>

            <div class="space-y-4">
                <x-toggle
                    label="Enable Guestbook"
                    wire:model.live="guestbook_enabled"
                    hint="Allow visitors to sign in when physically at your event location"
                />

                @if($guestbook_enabled)
                    <x-alert icon="phosphor-info" class="alert-info">
                        <div class="text-sm">
                            <strong>Location-based Check-in:</strong>
                            Visitors must be within the detection radius of your event location OR on a local subnet to sign the guestbook.
                            Set latitude and longitude in the <strong>Event Location</strong> section above.
                        </div>
                    </x-alert>

                    <div>
                        <x-input
                            label="Detection Radius (meters)"
                            wire:model.live="guestbook_detection_radius"
                            type="number"
                            min="100"
                            max="2000"
                            step="50"
                            icon="phosphor-cell-signal-high"
                            hint="How far from the event location visitors can check in (100-2000m)"
                        />
                        <div class="mt-2">
                            <x-range
                                wire:model.live="guestbook_detection_radius"
                                min="100"
                                max="2000"
                                step="50"
                                class="range-primary range-sm"
                            />
                            <div class="text-xs text-base-content/60 text-center mt-1">
                                Current: {{ $guestbook_detection_radius }}m
                                ({{ number_format($guestbook_detection_radius * 3.28084, 0) }} feet)
                            </div>
                        </div>
                    </div>

                    <x-textarea
                        label="Local Subnets (Optional)"
                        wire:model="guestbook_local_subnets"
                        rows="4"
                        icon="phosphor-globe"
                        placeholder="192.168.1.0/24&#10;10.0.0.0/8"
                        hint="One CIDR notation per line (e.g., 192.168.1.0/24). Visitors on these networks can sign in."
                    />
                @endif
            </div>
        </x-card>

        <!-- Submit Actions -->
        <div class="flex justify-end gap-3">
            <x-button
                label="Cancel"
                icon="phosphor-x"
                class="btn-ghost"
                link="{{ route('events.index') }}"
                wire:navigate
            />
            <x-button
                type="submit"
                label="{{ $mode === 'edit' ? 'Update Event' : 'Create Event' }}"
                icon="phosphor-check"
                class="btn-primary"
                spinner="save"
            />
        </div>
    </form>
</div>
