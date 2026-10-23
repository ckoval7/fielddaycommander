{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="space-y-6">
    <!-- Header -->
    <x-header
        title="{{ $mode === 'create' ? 'Create Event' : ($mode === 'clone' ? 'Clone Event' : 'Edit Event') }}"
        subtitle="{{ $mode === 'create' ? 'Set up a Field Day or Winter Field Day event' : ($mode === 'clone' ? 'Create a copy of this event with updated details' : 'Update event details and configuration') }}"
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

    <form wire:submit="save" novalidate>
        <!-- Section 1: Event Information -->
        <x-card class="mb-6">
            <x-slot:title>Event Information</x-slot:title>

            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div class="md:col-span-6">
                    <x-input
                        label="Event Name"
                        wire:model.live="name"
                        autocapitalize="words"
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
                        hint="{{ $rulesVersionLocked ? 'Locked because the event has started.' : 'Rules version used for scoring. Editable until the event starts.' }}"
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
                <p class="text-sm text-base-content/60">Location details are optional. Add the details that apply to your site.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input
                        label="Grid Square"
                        wire:model="gridSquare"
                        autocomplete="off"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        icon="phosphor-map-trifold"
                        placeholder="DM79"
                        hint="Maidenhead locator, such as DM79 or DM79ab."
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
                        autocapitalize="words"
                        icon="phosphor-buildings"
                        placeholder="Denver"
                        hint="Shown in auto-filled Section Manager messages as your place of origin."
                    />

                    <x-input
                        label="State / Province"
                        wire:model="state"
                        autocapitalize="characters"
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
                    autocomplete="off"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    required
                    icon="phosphor-radio"
                    placeholder="W1AW"
                    hint="Primary station callsign"
                    :disabled="$isLocked"
                />

                <x-input
                    label="Club Name"
                    wire:model="club_name"
                    autocapitalize="words"
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

            @if($this->isWinterFieldDay)
                <div class="collapse collapse-arrow bg-base-200 mt-4" data-testid="wfd-rules-guidance">
                    <input type="checkbox" aria-label="Toggle WFD rules guidance" />
                    <div class="collapse-title text-sm font-medium">
                        <x-icon name="phosphor-info" class="w-4 h-4 inline" />
                        WFD rules: class, category, and location
                    </div>
                    <div class="collapse-content text-sm space-y-3">
                        <p>
                            <strong>Class</strong> is the number of transmitters that can transmit at the same time
                            and that you have operators for. Do not count a station left unattended while you operate another.
                        </p>
                        <div>
                            <p><strong>Category</strong>: pick the one that applies to the majority of stations at your site.</p>
                            <ul class="list-disc ml-5 mt-1 space-y-0.5">
                                <li><strong>H, Home:</strong> inside a permanent livable residence.</li>
                                <li><strong>I, Indoor:</strong> away from home, inside an insulated building on a permanent foundation (club shack, EOC, church, cabin).</li>
                                <li><strong>O, Outdoor:</strong> tents, pavilions, canopies, or other shelter without plumbing or HVAC.</li>
                                <li><strong>M, Mobile:</strong> an RV, car, van, trailer, or anything able to become mobile.</li>
                            </ul>
                        </div>
                        <p>
                            Class, category, and location identifier stay the same for the whole event, even if you move.
                            A moving station uses the section of its first contact: ARRL/RAC section for the US and Canada,
                            MX for Mexico, DX for everyone else.
                        </p>
                        <p>
                            All operators, antennas, and feed lines must fit within a 300 m diameter circle.
                            No remote operation, and 100 W PEP maximum.
                        </p>
                    </div>
                </div>
            @endif
        </x-card>

        <!-- Section 4: Power Configuration -->
        <x-card class="mb-6">
            <x-slot:title>
                <div class="flex items-center justify-between">
                    <span>Power Configuration</span>
                    @if($this->usesPowerMultiplier)
                        <span class="badge badge-{{ $this->powerMultiplierColor }} badge-lg">
                            {{ $this->powerMultiplier }}× Multiplier
                        </span>
                    @endif
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
                    <div @class([
                        'rounded-box border p-3 -mx-3 transition-colors',
                        'border-error bg-error/5' => $errors->has('uses_commercial_power'),
                        'border-transparent' => ! $errors->has('uses_commercial_power'),
                    ])>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="flex flex-col">
                            <label class="flex items-center gap-3 cursor-pointer py-2">
                                <input
                                    type="checkbox"
                                    wire:model.live="uses_commercial_power"
                                    @class(['checkbox checkbox-sm', 'checkbox-error' => $errors->has('uses_commercial_power')])
                                    @if($isLocked) disabled @endif
                                />
                                <span class="text-sm">Commercial Power (Grid)</span>
                            </label>
                        </div>

                        <div class="flex flex-col">
                            <label class="flex items-center gap-3 cursor-pointer py-2">
                                <input
                                    type="checkbox"
                                    wire:model.live="uses_generator"
                                    @class(['checkbox checkbox-sm', 'checkbox-error' => $errors->has('uses_commercial_power')])
                                    @if($isLocked) disabled @endif
                                />
                                <span class="text-sm">Generator</span>
                            </label>
                        </div>

                        <div class="flex flex-col">
                            <label class="flex items-center gap-3 cursor-pointer py-2">
                                <input
                                    type="checkbox"
                                    wire:model.live="uses_battery"
                                    @class(['checkbox checkbox-sm', 'checkbox-error' => $errors->has('uses_commercial_power')])
                                    @if($isLocked) disabled @endif
                                />
                                <span class="text-sm">Battery</span>
                            </label>
                        </div>

                        <div class="flex flex-col">
                            <label class="flex items-center gap-3 cursor-pointer py-2">
                                <input
                                    type="checkbox"
                                    wire:model.live="uses_alternate_power"
                                    @class(['checkbox checkbox-sm', 'checkbox-error' => $errors->has('uses_commercial_power')])
                                    @if($isLocked) disabled @endif
                                />
                                <span class="text-sm">Alternate Power</span>
                            </label>
                            <p class="text-xs text-base-content/60 ml-9 -mt-2">
                                Examples: solar, wind, and water (hydro).
                            </p>
                        </div>
                    </div>
                    @error('uses_commercial_power')
                        <div class="text-error text-sm mt-2">{{ $message }}</div>
                    @enderror
                    </div>
                    </fieldset>
                </div>

                <x-input
                    label="Other Power Source"
                    wire:model="uses_other_power"
                    autocapitalize="sentences"
                    icon="phosphor-lightbulb"
                    placeholder="Describe any other power source"
                    hint="Optional. Describe any other power source."
                    :disabled="$isLocked"
                />

                @if($this->usesPowerMultiplier)
                    <x-alert icon="phosphor-info" class="alert-info">
                        <div class="text-sm">
                            <strong>Power multipliers</strong>
                            <p class="mt-1">The highest multiplier your entry qualifies for applies.</p>
                            <ul class="list-disc list-inside mt-1 space-y-1">
                                @foreach($this->powerMultiplierTiers as $tier)
                                    @php
                                        $wattage = match (true) {
                                            $tier['maxWatts'] === null => 'over '.($tier['minWatts'] - 1).' W',
                                            $tier['minWatts'] === 1 => $tier['maxWatts'].' W or less',
                                            default => $tier['minWatts'].' W to '.$tier['maxWatts'].' W',
                                        };
                                    @endphp
                                    <li>
                                        <strong>{{ $tier['multiplier'] }}×:</strong>
                                        {{ $wattage }}{{ $tier['naturalPowerOnly'] ? ', using only battery, solar, wind, or water power. No commercial power or generators.' : '' }}
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-2">Station settings count too. Higher station power, commercial power, or generator power can lower the multiplier.</p>
                        </div>
                    </x-alert>
                @endif
            </div>
        </x-card>

        <!-- Section 5: GOTA Station -->
        @if($this->allowsGota || $has_gota_station)
            <x-card class="mb-6">
                <x-slot:title>GOTA Station (Get On The Air)</x-slot:title>

                <div class="space-y-4">
                    <div class="flex flex-col">
                        <label class="flex items-center gap-3 cursor-pointer py-2">
                            <input
                                type="checkbox"
                                wire:model.live="has_gota_station"
                                class="checkbox"
                                @if($isLocked) disabled @endif
                            />
                            <span class="text-sm">Enable GOTA Station</span>
                        </label>
                        <p class="text-sm text-base-content/60 mt-1 ml-9">
                            Allow novice operators to make contacts under supervision
                        </p>
                    </div>

                    @if($has_gota_station)
                        <x-input
                            label="GOTA Callsign"
                            wire:model="gota_callsign"
                            autocomplete="off"
                            autocapitalize="characters"
                            autocorrect="off"
                            spellcheck="false"
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

        <!-- Public Visitors -->
        <x-card class="mb-6">
            <x-slot:title>Public Visitors</x-slot:title>

            <div class="collapse collapse-arrow bg-base-200" data-testid="public-visitors-section">
                <input type="checkbox" aria-label="Toggle public visitor details" @checked($is_open_to_public) />
                <div class="collapse-title text-sm font-medium">
                    Optional details for visitors (shown on the public landing page)
                </div>
                <div class="collapse-content space-y-4">
                    <x-toggle
                        label="Open to the public"
                        wire:model.live="is_open_to_public"
                        hint="Visitors are welcome. Details below are published only when this is on."
                    />

                    <x-textarea
                        label="Directions / how to find us"
                        wire:model="public_directions"
                        rows="3"
                        maxlength="1000"
                        hint="Address, landmarks, parking. Plain text only."
                    />

                    <x-textarea
                        label="Setup, operating, and best visiting times"
                        wire:model="public_visiting_hours"
                        rows="2"
                        maxlength="500"
                        hint="Plain text only."
                    />

                    <x-input
                        label="Contact email"
                        wire:model="public_contact_email"
                        type="email"
                        icon="phosphor-envelope"
                        maxlength="255"
                        placeholder="info@example.org"
                        hint="Optional. Shown on the public page."
                    />

                    <x-input
                        label="Contact phone"
                        wire:model="public_contact_phone"
                        type="tel"
                        icon="phosphor-phone"
                        maxlength="50"
                        placeholder="555-555-0100"
                        hint="Optional. Shown on the public page."
                    />

                    <x-input
                        label="Website or social page"
                        wire:model="public_website_url"
                        type="url"
                        icon="phosphor-globe"
                        maxlength="255"
                        placeholder="https://example.org"
                        hint="Optional. Website or social page, starting with http:// or https://. Shown on the public page."
                    />

                    <x-textarea
                        label="What visitors will see"
                        wire:model="public_description"
                        rows="5"
                        maxlength="2000"
                        hint="Two or three short paragraphs about what is happening at your site. Plain text only."
                    />
                </div>
            </div>
        </x-card>

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
                            Visitors must be within the detection radius of your event location or on a local subnet to sign the guestbook.
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
                        :placeholder="'192.168.1.0/24'.PHP_EOL.'10.0.0.0/8'"
                        hint="One network in CIDR notation per line (e.g., 192.168.1.0/24). Visitors on these networks can sign in."
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
