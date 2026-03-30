<x-layouts.guest>
    {{-- Progress Stepper --}}
    <ul class="steps steps-horizontal w-full mb-8">
        <li class="step {{ $step >= 1 ? 'step-primary' : '' }}">Admin Password</li>
        <li class="step {{ $step >= 2 ? 'step-primary' : '' }}">Site Branding</li>
        <li class="step {{ $step >= 3 ? 'step-primary' : '' }}">Preferences</li>
    </ul>

    <div class="mb-6">
        <h2 class="text-2xl font-bold">Step 3: System Preferences</h2>
    </div>

    <div class="space-y-6">
        <p class="text-center">Configure essential system settings.</p>

        <form method="POST" action="{{ route('setup.complete') }}" class="space-y-6">
            @csrf

            @php
                $timezones = collect(timezone_identifiers_list())->map(fn($tz) => ['id' => $tz, 'name' => str_replace('_', ' ', $tz)])->all();
                $dateFormats = [
                    ['id' => 'Y-m-d', 'name' => now()->format('Y-m-d') . ' (ISO)'],
                    ['id' => 'm/d/Y', 'name' => now()->format('m/d/Y') . ' (US)'],
                    ['id' => 'd/m/Y', 'name' => now()->format('d/m/Y') . ' (EU)'],
                ];
                $timeFormats = [
                    ['id' => 'H:i', 'name' => now()->format('H:i') . ' (24-hour)'],
                    ['id' => 'h:i A', 'name' => now()->format('h:i A') . ' (12-hour)'],
                ];
            @endphp

            {{-- Organization Information --}}
            <div class="space-y-4">
                <h3 class="text-lg font-semibold mb-2">Organization Information</h3>
                <p class="text-sm text-gray-600 mb-4">Set up your club or organization details. This will be used for club-owned equipment.</p>

                <x-input
                    label="Organization Name"
                    name="organization_name"
                    icon="o-building-office"
                    placeholder="e.g., Springfield Amateur Radio Club"
                    required
                    errorField="organization_name"
                />

                <x-input
                    label="Organization Callsign"
                    name="organization_callsign"
                    icon="o-signal"
                    placeholder="e.g., W1ABC"
                    hint="Optional - Club station callsign (3-10 uppercase letters/numbers)"
                    errorField="organization_callsign"
                />

                <x-input
                    label="Organization Email"
                    type="email"
                    name="organization_email"
                    icon="o-envelope"
                    placeholder="e.g., info@example.org"
                    hint="Optional - Club contact email"
                    errorField="organization_email"
                />

                <x-input
                    label="Organization Phone"
                    type="tel"
                    name="organization_phone"
                    icon="o-phone"
                    placeholder="e.g., (555) 123-4567"
                    hint="Optional - Club contact phone number"
                    errorField="organization_phone"
                />
            </div>

            {{-- System Preferences --}}
            <div class="space-y-4 mt-6">
                <h3 class="text-lg font-semibold mb-2">System Preferences</h3>

                {{-- Searchable timezone using Alpine.js (non-Livewire form) --}}
                <div
                    x-data="{
                        open: false,
                        search: '',
                        selected: '{{ old('timezone') }}',
                        selectedLabel: '{{ old('timezone') ? str_replace('_', ' ', old('timezone')) : '' }}',
                        timezones: @js($timezones),
                        get filtered() {
                            if (!this.search) return this.timezones;
                            const q = this.search.toLowerCase();
                            return this.timezones.filter(t => t.name.toLowerCase().includes(q));
                        },
                        select(tz) {
                            this.selected = tz.id;
                            this.selectedLabel = tz.name;
                            this.search = '';
                            this.open = false;
                        }
                    }"
                    @click.outside="open = false"
                    class="form-control w-full"
                >
                    <label class="label" for="timezone-selector"><span class="label-text font-semibold">Timezone <span class="text-error">*</span></span></label>
                    <input type="hidden" name="timezone" :value="selected" required />
                    <div class="relative">
                        <button
                            id="timezone-selector"
                            type="button"
                            @click="open = !open"
                            class="select select-bordered w-full text-left flex items-center justify-between"
                            :class="{ 'select-error': !selected }"
                        >
                            <span x-text="selectedLabel || 'Select timezone...'" :class="selectedLabel ? '' : 'text-base-content/40'"></span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </button>
                        <div
                            x-show="open"
                            x-transition
                            class="absolute z-50 mt-1 w-full bg-base-100 border border-base-300 rounded-box shadow-lg"
                        >
                            <div class="p-2">
                                <input
                                    type="text"
                                    x-model="search"
                                    placeholder="Search timezones..."
                                    class="input input-sm input-bordered w-full"
                                    @click.stop
                                    x-ref="searchInput"
                                    x-init="$watch('open', v => v && $nextTick(() => $refs.searchInput.focus()))"
                                />
                            </div>
                            <ul class="max-h-60 overflow-y-auto py-1">
                                <template x-for="tz in filtered" :key="tz.id">
                                    <li
                                        @click="select(tz)"
                                        class="px-3 py-1.5 cursor-pointer hover:bg-base-200 text-sm"
                                        :class="selected === tz.id ? 'bg-primary/10 font-medium' : ''"
                                        x-text="tz.name"
                                    ></li>
                                </template>
                                <li x-show="filtered.length === 0" class="px-3 py-2 text-sm text-base-content/50">No results found.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <x-select
                    label="Date Format"
                    name="date_format"
                    :options="$dateFormats"
                    placeholder="Select format..."
                    required
                />

                <x-select
                    label="Time Format"
                    name="time_format"
                    :options="$timeFormats"
                    placeholder="Select format..."
                    required
                />

                <x-input
                    label="Contact Email"
                    type="email"
                    name="contact_email"
                    icon="o-envelope"
                    hint="Optional - for public contact information"
                    errorField="contact_email"
                />
            </div>

            <div class="flex justify-between">
                <x-button
                    type="button"
                    onclick="window.location='{{ route('setup.branding') }}'"
                    onkeydown="if(event.key==='Enter'||event.key===' '){window.location='{{ route('setup.branding') }}'}"
                    class="btn-ghost"
                    icon="o-arrow-left"
                >
                    Back
                </x-button>

                <x-button type="submit" class="btn-success" icon-right="o-check-circle">
                    Complete Setup
                </x-button>
            </div>
        </form>
    </div>
</x-layouts.guest>
