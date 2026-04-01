<x-form wire:submit="saveProfile">
    <div class="space-y-6">
        {{-- Basic Information Section --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Basic Information</h3>

                {{-- Call Sign (Read-only) --}}
                <x-input
                    label="Call Sign"
                    wire:model="call_sign"
                    disabled
                    hint="Contact an administrator to change your call sign"
                />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- First Name --}}
                    <x-input
                        label="First Name"
                        wire:model="first_name"
                        required
                    />

                    {{-- Last Name --}}
                    <x-input
                        label="Last Name"
                        wire:model="last_name"
                        required
                    />
                </div>

                {{-- Email --}}
                <x-input
                    label="Email"
                    type="email"
                    wire:model="email"
                    required
                    hint="Changing your email will require verification"
                />

                {{-- License Class --}}
                <x-select
                    label="License Class"
                    wire:model="license_class"
                    :options="[
                        ['value' => '', 'label' => 'Not specified'],
                        ['value' => 'Technician', 'label' => 'Technician'],
                        ['value' => 'General', 'label' => 'General'],
                        ['value' => 'Advanced', 'label' => 'Advanced'],
                        ['value' => 'Extra', 'label' => 'Extra'],
                    ]"
                    option-value="value"
                    option-label="label"
                />
            </div>
        </div>

        {{-- Preferences Section --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Preferences</h3>

                {{-- Timezone --}}
                <x-choices-offline
                    label="Preferred Timezone"
                    wire:model="preferred_timezone"
                    :options="collect(timezone_identifiers_list())->map(fn($tz) => ['id' => $tz, 'name' => str_replace('_', ' ', $tz)])->toArray()"
                    placeholder="Search timezone..."
                    single
                    searchable
                    hint="Used for displaying timestamps in your local time"
                />

                {{-- Email Notifications --}}
                <div>
                    <div class="text-sm font-semibold mb-2">Email Notifications</div>

                    <div class="space-y-2">
                        {{-- Security Alerts (Always enabled) --}}
                        <div class="flex items-center gap-2">
                            <x-checkbox checked disabled />
                            <span>Security Alerts <span class="text-xs text-base-content/60">(always enabled)</span></span>
                            <div class="tooltip" data-tip="Security notifications cannot be disabled for your protection">
                                <x-icon name="o-information-circle" class="w-4 h-4 text-base-content/40 cursor-help" />
                            </div>
                        </div>

                        {{-- Event Notifications --}}
                        <x-checkbox
                            label="Event Notifications"
                            wire:model="event_notifications"
                            hint="Event starting/ending reminders and station assignments"
                        />

                        {{-- System Announcements --}}
                        <x-checkbox
                            label="System Announcements"
                            wire:model="system_announcements"
                            hint="System maintenance and important updates"
                        />
                    </div>
                </div>

                <div class="card-actions justify-end mt-4">
                    <x-button type="submit" spinner="saveProfile" class="btn-primary">
                        Save Changes
                    </x-button>
                </div>
            </div>
        </div>

        {{-- In-App Notification Categories --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="card-title">In-App Notification Categories</h3>
                        <p class="text-sm text-base-content/60 mt-1">Choose which types of activity notifications you receive</p>
                    </div>
                    <div>
                        @if($this->allCategoriesEnabled)
                            <x-button wire:click="toggleAllCategories(false)" class="btn-sm btn-ghost" icon="o-x-mark">
                                Disable All
                            </x-button>
                        @else
                            <x-button wire:click="toggleAllCategories(true)" class="btn-sm btn-ghost" icon="o-check">
                                Enable All
                            </x-button>
                        @endif
                    </div>
                </div>

                <div class="divider my-1"></div>

                <div class="space-y-4">
                    {{-- New Section Worked --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="o-globe-americas" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">New Section Worked</div>
                                <div class="text-sm text-base-content/60">When a new ARRL/RAC section is worked for the first time</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_new_section" />
                    </div>

                    {{-- Guestbook Entries --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="o-book-open" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Guestbook Entries</div>
                                <div class="text-sm text-base-content/60">When visitors sign the guestbook</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_guestbook" />
                    </div>

                    {{-- Photo Uploads --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="o-photo" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Photo Uploads</div>
                                <div class="text-sm text-base-content/60">When new photos are uploaded to the gallery</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_photos" />
                    </div>

                    {{-- Station Status --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="o-signal" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Station Status</div>
                                <div class="text-sm text-base-content/60">When a station becomes available or occupied</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_station_status" />
                    </div>

                    {{-- QSO Milestones --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="o-trophy" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">QSO Milestones</div>
                                <div class="text-sm text-base-content/60">When the event reaches QSO count milestones</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_qso_milestone" />
                    </div>

                    {{-- Equipment Changes --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="o-wrench-screwdriver" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Equipment Changes</div>
                                <div class="text-sm text-base-content/60">When equipment status changes</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_equipment" />
                    </div>

                    {{-- Bulletin Reminders --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="o-radio" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Bulletin Reminders</div>
                                <div class="text-sm text-base-content/60">W1AW bulletin transmission reminders</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_bulletin_reminder" />
                    </div>
                </div>

                <div class="card-actions justify-end mt-4">
                    <x-button type="submit" spinner="saveProfile" class="btn-primary">
                        Save Changes
                    </x-button>
                </div>
            </div>
        </div>
    </div>
</x-form>
