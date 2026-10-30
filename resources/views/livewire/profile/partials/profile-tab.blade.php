{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<x-form wire:submit="saveProfile">
    <div class="space-y-6">
        {{-- Basic Information Section --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h2 class="card-title">Basic Information</h2>

                {{-- Call Sign (Read-only) --}}
                <x-input
                    label="Call Sign"
                    wire:model="call_sign"
                    autocomplete="off"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    disabled
                    hint="Contact an administrator to change your call sign"
                />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {{-- First Name --}}
                    <x-input
                        label="First Name"
                        wire:model="first_name"
                        autocapitalize="words"
                        autocomplete="given-name"
                        required
                    />

                    {{-- Last Name --}}
                    <x-input
                        label="Last Name"
                        wire:model="last_name"
                        autocapitalize="words"
                        autocomplete="family-name"
                        required
                    />
                </div>

                {{-- Email --}}
                <x-input
                    label="Email"
                    type="email"
                    wire:model="email"
                    autocomplete="email"
                    required
                    hint="Changing your email will require verification"
                />

                {{-- Current password, only needed when the email changes --}}
                <div x-show="($wire.email ?? '').toLowerCase() !== ($wire.savedEmail ?? '').toLowerCase()" x-cloak>
                    <x-input
                        label="Current Password"
                        type="password"
                        wire:model="emailChangePassword"
                        autocomplete="current-password"
                        hint="Enter your current password to change your email"
                    />
                </div>

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

                {{-- Youth Flag --}}
                <label class="flex items-center gap-3 cursor-pointer py-2 mt-2">
                    <input type="checkbox" class="checkbox checkbox-sm" wire:model="is_youth" />
                    <span class="text-sm">Youth (age 18 or younger)</span>
                </label>

                {{-- CPR / AED Trained --}}
                <label class="flex items-center gap-3 cursor-pointer py-2">
                    <input type="checkbox" class="checkbox checkbox-sm" wire:model="is_cpr_aed_trained" />
                    <span class="text-sm">CPR / AED trained</span>
                </label>
            </div>
        </div>

        {{-- Preferences Section --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h2 class="card-title">Preferences</h2>

                {{-- Timezone --}}
                <x-choices-offline
                    label="Preferred Time Zone"
                    wire:model="preferred_timezone"
                    :options="collect(timezone_identifiers_list())->map(fn($tz) => ['id' => $tz, 'name' => str_replace('_', ' ', $tz)])->toArray()"
                    placeholder="Search time zones..."
                    single
                    searchable
                    hint="Used where times are shown in your local time"
                />

                {{-- Email Notifications --}}
                @if(config('mail.email_configured'))
                    <div>
                        <div class="text-sm font-semibold mb-2">Email Notifications</div>

                        <div class="space-y-2">
                            {{-- Security Alerts (Always enabled) --}}
                            <div class="flex items-center gap-2">
                                <x-checkbox checked disabled aria-label="Security Alerts (always enabled)" />
                                <span>Security Alerts <span class="text-xs text-base-content/60">(always enabled)</span></span>
                                <div class="tooltip" data-tip="Security alerts are always enabled">
                                    <x-icon name="phosphor-info" class="w-4 h-4 text-base-content/40 cursor-help" />
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

                            {{-- Weather Alerts --}}
                            <x-checkbox
                                label="Email weather alerts"
                                wire:model="weatherAlertEmail"
                                hint="Send an email when weather alerts become active"
                            />
                        </div>
                    </div>
                @endif

                <div class="card-actions justify-end mt-4">
                    <x-button type="submit" spinner="saveProfile" class="btn-primary">
                        Save Changes
                    </x-button>
                </div>
            </div>
        </div>

        {{-- In-App Notification Categories --}}
        <div id="notification-categories" class="card bg-base-100 shadow scroll-mt-20"
            x-init="if (window.location.hash === '#notification-categories') $el.scrollIntoView({ behavior: 'smooth' })"
        >
            <div class="card-body">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="card-title">In-App Notification Categories</h2>
                        <p class="text-sm text-base-content/60 mt-1">Choose which types of activity notifications you receive</p>
                    </div>
                    <div>
                        @if($this->allCategoriesEnabled)
                            <x-button wire:click="toggleAllCategories(false)" class="btn-sm btn-ghost" icon="phosphor-x">
                                Disable All
                            </x-button>
                        @else
                            <x-button wire:click="toggleAllCategories(true)" class="btn-sm btn-ghost" icon="phosphor-check">
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
                            <x-icon name="phosphor-globe" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">New Section Worked</div>
                                <div class="text-sm text-base-content/60">When a new ARRL/RAC section is worked</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_new_section" aria-label="New Section Worked" />
                    </div>

                    {{-- Guestbook Entries --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="phosphor-book-open" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Guestbook Entries</div>
                                <div class="text-sm text-base-content/60">When visitors sign the guestbook</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_guestbook" aria-label="Guestbook Entries" />
                    </div>

                    {{-- Photo Uploads --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="phosphor-image" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Photo Uploads</div>
                                <div class="text-sm text-base-content/60">When new photos are uploaded to the gallery</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_photos" aria-label="Photo Uploads" />
                    </div>

                    {{-- Station Status --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="phosphor-cell-signal-high" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Station Status</div>
                                <div class="text-sm text-base-content/60">When a station becomes available or occupied</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_station_status" aria-label="Station Status" />
                    </div>

                    {{-- QSO Milestones --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="phosphor-trophy" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">QSO Milestones</div>
                                <div class="text-sm text-base-content/60">When the event reaches QSO count milestones</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_qso_milestone" aria-label="QSO Milestones" />
                    </div>

                    {{-- Equipment Changes --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="phosphor-wrench" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Equipment Changes</div>
                                <div class="text-sm text-base-content/60">When equipment status changes</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_equipment" aria-label="Equipment Changes" />
                    </div>

                    {{-- Bulletin Reminders --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="phosphor-radio" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Bulletin Reminders</div>
                                <div class="text-sm text-base-content/60">Bulletin transmission reminders</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_bulletin_reminder" aria-label="Bulletin Reminders" />
                    </div>

                    {{-- Shift Check-in Reminders --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="phosphor-clock" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Shift Check-in Reminders</div>
                                <div class="text-sm text-base-content/60">Reminders before your scheduled shifts</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notify_shift_checkin_reminder" aria-label="Shift Check-in Reminders" />
                    </div>

                    {{-- Weather Alerts --}}
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="phosphor-cloud" class="w-5 h-5 text-primary shrink-0" />
                            <div class="min-w-0">
                                <div class="font-medium">Weather Alerts</div>
                                <div class="text-sm text-base-content/60">NWS and manual weather alerts</div>
                            </div>
                        </div>
                        <x-toggle wire:model="notifyWeatherAlert" aria-label="Weather Alerts" />
                    </div>

                    {{-- Shift Confirmations (shift managers only) --}}
                    @can('manage-shifts')
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3 min-w-0">
                                <x-icon name="phosphor-seal-check" class="w-5 h-5 text-primary shrink-0" />
                                <div class="min-w-0">
                                    <div class="font-medium">Shift Confirmations</div>
                                    <div class="text-sm text-base-content/60">Volunteer check-ins waiting for your confirmation</div>
                                </div>
                            </div>
                            <x-toggle wire:model="notifyShiftConfirmation" aria-label="Shift Confirmations" />
                        </div>
                    @endcan
                </div>

                {{-- Shift Reminder Settings --}}
                @if($notify_shift_checkin_reminder)
                    <div class="divider my-1"></div>

                    <div class="space-y-4">
                        <h3 class="text-sm font-semibold">Shift Reminder Settings</h3>

                        {{-- Email toggle --}}
                        @if(config('mail.email_configured'))
                            <x-checkbox
                                label="Email shift reminders"
                                wire:model="shift_reminder_email"
                                hint="Send an email in addition to the in-app notification"
                            />
                        @endif

                        {{-- Reminder intervals --}}
                        <div>
                            <div class="text-sm font-medium mb-2">Reminder intervals</div>

                            @if(count($this->shiftReminderMinutes) > 0)
                                <div class="flex flex-wrap gap-2 mb-3">
                                    @foreach($this->shiftReminderMinutes as $minutes)
                                        <span class="badge badge-primary gap-1">
                                            {{ $minutes }} min
                                            <button
                                                type="button"
                                                wire:click="removeShiftReminderMinute({{ $minutes }})"
                                                class="btn btn-ghost btn-xs btn-square -mr-1.5"
                                                aria-label="Remove {{ $minutes }}-minute reminder"
                                            >
                                                <x-icon name="phosphor-x" class="w-3 h-3" />
                                            </button>
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-sm text-base-content/70 mb-3">No shift reminders set. Add a time from 1 to 60 minutes before your shift.</p>
                            @endif

                            @if(count($this->shiftReminderMinutes) < 5)
                                <div class="flex items-end gap-2">
                                    <x-input
                                        label="Minutes before"
                                        wire:model="shiftReminderMinute"
                                        type="number"
                                        min="1"
                                        max="60"
                                        placeholder="e.g., 15"
                                        class="w-32"
                                    />
                                    <x-button
                                        label="Add Reminder"
                                        wire:click="addShiftReminderMinute"
                                        class="btn-primary btn-sm"
                                        icon="phosphor-plus"
                                        spinner="addShiftReminderMinute"
                                    />
                                </div>
                                @error('shiftReminderMinute')
                                    <p class="text-error text-xs mt-1">{{ $message }}</p>
                                @enderror
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Bulletin Reminder Settings --}}
                @if($notify_bulletin_reminder)
                    <div class="divider my-1"></div>

                    <div class="space-y-4">
                        <h3 class="text-sm font-semibold">Bulletin Reminder Settings</h3>

                        {{-- Reminder intervals --}}
                        <div>
                            <div class="text-sm font-medium mb-2">Reminder intervals</div>

                            @if(count($this->bulletinReminderMinutes) > 0)
                                <div class="flex flex-wrap gap-2 mb-3">
                                    @foreach($this->bulletinReminderMinutes as $minutes)
                                        <span class="badge badge-primary gap-1">
                                            {{ $minutes }} min
                                            <button
                                                type="button"
                                                wire:click="removeBulletinReminderMinute({{ $minutes }})"
                                                class="btn btn-ghost btn-xs btn-square -mr-1.5"
                                                aria-label="Remove {{ $minutes }}-minute reminder"
                                            >
                                                <x-icon name="phosphor-x" class="w-3 h-3" />
                                            </button>
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-sm text-base-content/70 mb-3">No bulletin reminders set. Add a time from 1 to 60 minutes before a transmission.</p>
                            @endif

                            @if(count($this->bulletinReminderMinutes) < 5)
                                <div class="flex items-end gap-2">
                                    <x-input
                                        label="Minutes before"
                                        wire:model="bulletinReminderMinute"
                                        type="number"
                                        min="1"
                                        max="60"
                                        placeholder="e.g., 15"
                                        class="w-32"
                                    />
                                    <x-button
                                        label="Add Reminder"
                                        wire:click="addBulletinReminderMinute"
                                        class="btn-primary btn-sm"
                                        icon="phosphor-plus"
                                        spinner="addBulletinReminderMinute"
                                    />
                                </div>
                                @error('bulletinReminderMinute')
                                    <p class="text-error text-xs mt-1">{{ $message }}</p>
                                @enderror
                            @endif
                        </div>
                    </div>
                @endif

                <div class="card-actions justify-end mt-4">
                    <x-button type="submit" spinner="saveProfile" class="btn-primary">
                        Save Changes
                    </x-button>
                </div>
            </div>
        </div>
    </div>
</x-form>
