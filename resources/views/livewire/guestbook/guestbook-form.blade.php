{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div
    x-data="{
        eventLat: {{ $this->eventLocation['latitude'] ?? 'null' }},
        eventLon: {{ $this->eventLocation['longitude'] ?? 'null' }},
        radius: {{ $this->eventLocation['radius'] }},
        kiosk: @js($kiosk),
        locationStatus: 'idle',
        locationMessage: '',
        userDistance: null,

        init() {
            // Auto-detect location on page load if event has location configured.
            // A kiosk sits at the event, so its visitors are always in person.
            if (!this.kiosk && this.eventLat !== null && this.eventLon !== null) {
                this.detectLocation();
            }
        },

        detectLocation() {
            {{-- Browsers only allow location on HTTPS, and field sites often serve plain HTTP.
                 Leave the choice to the visitor rather than guessing they're remote. --}}
            if (!window.isSecureContext) {
                this.locationStatus = 'unsupported';
                this.locationMessage = 'Choose In Person or Online below.';
                $wire.clearPresenceType();
                return;
            }

            if (!navigator.geolocation) {
                this.locationStatus = 'unsupported';
                this.locationMessage = 'Your browser cannot check your location. Online is selected. Choose In Person if you are at the event.';
                $wire.setPresenceType('online');
                return;
            }

            this.locationStatus = 'detecting';
            this.locationMessage = 'Checking your location...';

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const userLat = position.coords.latitude;
                    const userLon = position.coords.longitude;
                    const distance = this.calculateDistance(userLat, userLon, this.eventLat, this.eventLon);
                    this.userDistance = Math.round(distance);

                    if (distance <= this.radius) {
                        this.locationStatus = 'in_person';
                        this.locationMessage = `You appear to be at the event (${this.userDistance} m away). Change the selection below if needed.`;
                        $wire.setPresenceType('in_person');
                    } else {
                        this.locationStatus = 'online';
                        this.locationMessage = `You appear to be away from the event (${this.formatDistance(distance)} away). Change the selection below if needed.`;
                        $wire.setPresenceType('online');
                    }
                },
                (error) => {
                    this.locationStatus = 'error';
                    // Default to online when location detection fails
                    $wire.setPresenceType('online');

                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            this.locationMessage = 'Location access is blocked. Online is selected. Choose In Person if you are at the event.';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            this.locationMessage = 'Your location is unavailable. Online is selected. Choose In Person if you are at the event.';
                            break;
                        case error.TIMEOUT:
                            this.locationMessage = 'The location check timed out. Online is selected. Choose In Person if you are at the event.';
                            break;
                        default:
                            this.locationMessage = 'Your location could not be checked. Online is selected. Choose In Person if you are at the event.';
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 300000
                }
            );
        },

        calculateDistance(lat1, lon1, lat2, lon2) {
            // Haversine formula for distance between two points
            const R = 6371000; // Earth's radius in meters
            const dLat = this.toRad(lat2 - lat1);
            const dLon = this.toRad(lon2 - lon1);
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                      Math.cos(this.toRad(lat1)) * Math.cos(this.toRad(lat2)) *
                      Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c;
        },

        toRad(deg) {
            return deg * (Math.PI / 180);
        },

        formatDistance(meters) {
            if (meters < 1000) {
                return `${Math.round(meters)}m`;
            }
            return `${(meters / 1000).toFixed(1)}km`;
        }
    }"
    class="space-y-6"
>
    {{-- Success Message --}}
    @if($showSuccess)
        <x-alert
            title="Thank you for signing our guestbook."
            description="Your entry has been saved."
            icon="phosphor-check-circle"
            class="alert-success"
            dismissible
            wire:click="hideSuccess"
        />
    @endif

    {{-- Form Card --}}
    @if(!$showSuccess)
        @if($eventConfig)
            <x-card title="Sign the Guestbook" subtitle="Your name and comments will appear in the visitor list.">
                <form wire:submit="save" class="space-y-4">
                    {{-- Honeypot field - hidden from real users, bots will fill it --}}
                    <div class="hidden" aria-hidden="true">
                        <label for="website">Website</label>
                        <input
                            type="text"
                            name="website"
                            id="website"
                            wire:model="honeypot"
                            tabindex="-1"
                            autocomplete="off"
                        />
                    </div>

                    {{-- Form error display --}}
                    @error('form')
                        <x-alert
                            title="Error"
                            :description="$message"
                            icon="phosphor-warning"
                            class="alert-error"
                            dismissible
                        />
                    @enderror

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {{-- Name --}}
                        <x-input
                            label="Name"
                            wire:model="name"
                            autocapitalize="words"
                            autocomplete="name"
                            icon="phosphor-user"
                            placeholder="Your full name"
                            required
                        />

                        {{-- Callsign --}}
                        <x-input
                            label="Radio call sign (optional)"
                            wire:model="callsign"
                            autocomplete="off"
                            autocapitalize="characters"
                            autocorrect="off"
                            spellcheck="false"
                            icon="phosphor-cell-signal-high"
                            placeholder="For example, W1AW"
                            hint="Leave blank if you do not have a radio call sign."
                        />
                    </div>

                    {{-- Email --}}
                    <x-input
                        label="Email (optional)"
                        wire:model="email"
                        autocomplete="email"
                        type="email"
                        icon="phosphor-envelope"
                        placeholder="you@example.com"
                        hint="Your email is not shown in the visitor list."
                    />

                    {{-- Visitor Category --}}
                    <x-select
                        label="I am visiting as"
                        wire:model="visitor_category"
                        :options="$this->visitorCategories"
                        option-value="value"
                        option-label="label"
                        icon="phosphor-users-three"
                        required
                    />

                    {{-- Presence Type --}}
                    <div class="space-y-2">
                        <x-radio
                            label="How are you visiting?"
                            wire:model="presence_type"
                            :options="$this->presenceTypes"
                            option-value="value"
                            option-label="label"
                        />

                        {{-- Location detection hint --}}
                        @if($this->hasEventLocation && ! $kiosk)
                            <div
                                x-show="locationMessage"
                                x-transition
                                class="flex items-center gap-2 text-sm"
                                :class="{
                                    'text-info': locationStatus === 'detecting',
                                    'text-success': locationStatus === 'in_person',
                                    'text-warning': locationStatus === 'online' || locationStatus === 'error',
                                    'text-base-content/70': locationStatus === 'unsupported'
                                }"
                            >
                                <template x-if="locationStatus === 'detecting'">
                                    <span class="loading loading-spinner loading-xs"></span>
                                </template>
                                <template x-if="locationStatus === 'in_person'">
                                    <x-icon name="phosphor-map-pin" class="w-4 h-4" />
                                </template>
                                <template x-if="locationStatus === 'online'">
                                    <x-icon name="phosphor-globe" class="w-4 h-4" />
                                </template>
                                <template x-if="locationStatus === 'error' || locationStatus === 'unsupported'">
                                    <x-icon name="phosphor-warning-circle" class="w-4 h-4" />
                                </template>
                                <span x-text="locationMessage"></span>
                            </div>

                            <button
                                type="button"
                                x-show="locationStatus !== 'detecting'"
                                @click="detectLocation()"
                                class="btn btn-xs btn-ghost"
                            >
                                <x-icon name="phosphor-arrow-clockwise" class="w-3 h-3" />
                                Check location again
                            </button>
                        @endif
                    </div>

                    {{-- Comments --}}
                    <x-textarea
                        label="Comments (optional)"
                        wire:model="comments"
                        placeholder="Share your thoughts about the event."
                        hint="Maximum 500 characters"
                        rows="3"
                    />

                    {{-- Character counter for comments --}}
                    <div class="text-right text-xs text-base-content/60">
                        <span>{{ strlen($comments) }}</span> / 500 characters
                    </div>

                    {{-- Submit Button --}}
                    <div class="flex justify-end pt-4">
                        <x-button
                            label="Sign Guestbook"
                            type="submit"
                            class="btn-primary"
                            icon="phosphor-note-pencil"
                            spinner="save"
                        />
                    </div>
                </form>
            </x-card>
        @else
            {{-- No active event with guestbook enabled --}}
            <x-alert
                title="Guestbook Closed"
                description="The guestbook is not accepting entries. Check back later."
                icon="phosphor-info"
                class="alert-info"
            />
        @endif
    @else
        {{-- After success, show option to add another entry --}}
        <x-card class="text-center">
            <div class="py-8 space-y-4">
                <x-icon name="phosphor-check-circle" class="w-16 h-16 mx-auto text-success" />
                <h3 class="text-xl font-semibold">Thank You</h3>
                <p class="text-base-content/70">Your guestbook entry has been recorded.</p>
                {{-- On a kiosk, a ring beside the button counts down and clears the thank-you screen for the next visitor --}}
                <div
                    @if($kiosk)
                        x-data="{
                            total: {{ $this::KIOSK_THANK_YOU_SECONDS * 1000 }},
                            left: {{ $this::KIOSK_THANK_YOU_SECONDS * 1000 }},
                            timer: null,
                            init() {
                                const endsAt = Date.now() + this.total;
                                this.timer = setInterval(() => {
                                    this.left = Math.max(0, endsAt - Date.now());
                                    if (this.left === 0) {
                                        clearInterval(this.timer);
                                        $wire.hideSuccess();
                                    }
                                }, 100);
                            },
                            destroy() {
                                clearInterval(this.timer);
                            },
                        }"
                        data-kiosk-countdown
                    @endif
                    class="flex items-center justify-center gap-3"
                >
                    <x-button
                        label="Add Another Entry"
                        wire:click="hideSuccess"
                        class="btn-primary"
                        icon="phosphor-plus"
                    />

                    @if($kiosk)
                        {{-- Same height as the button beside it: 2.5rem, or 2.75rem on touchscreens (see app.css) --}}
                        <div
                            class="radial-progress text-primary [--size:2.5rem] pointer-coarse:[--size:2.75rem] [--thickness:4px]"
                            style="--value: 100"
                            :style="`--value: ${left / total * 100}`"
                            aria-hidden="true"
                        ></div>
                        <span class="sr-only">This screen clears for the next visitor in {{ $this::KIOSK_THANK_YOU_SECONDS }} seconds.</span>
                    @endif
                </div>
            </div>
        </x-card>
    @endif
</div>
