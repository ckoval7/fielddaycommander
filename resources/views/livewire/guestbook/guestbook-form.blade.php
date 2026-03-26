<div
    x-data="{
        eventLat: {{ $this->eventLocation['latitude'] ?? 'null' }},
        eventLon: {{ $this->eventLocation['longitude'] ?? 'null' }},
        radius: {{ $this->eventLocation['radius'] }},
        locationStatus: 'idle',
        locationMessage: '',
        userDistance: null,

        init() {
            // Auto-detect location on page load if event has location configured
            if (this.eventLat !== null && this.eventLon !== null) {
                this.detectLocation();
            }
        },

        detectLocation() {
            if (!navigator.geolocation) {
                this.locationStatus = 'unsupported';
                this.locationMessage = '🌐 Geolocation is not supported by your browser - signing in remotely';
                $wire.setPresenceType('online');
                return;
            }

            this.locationStatus = 'detecting';
            this.locationMessage = '📍 Detecting your location...';

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const userLat = position.coords.latitude;
                    const userLon = position.coords.longitude;
                    const distance = this.calculateDistance(userLat, userLon, this.eventLat, this.eventLon);
                    this.userDistance = Math.round(distance);

                    if (distance <= this.radius) {
                        this.locationStatus = 'in_person';
                        this.locationMessage = `📍 Looks like you're here at the event! (${this.userDistance}m away)`;
                        $wire.setPresenceType('in_person');
                    } else {
                        this.locationStatus = 'online';
                        this.locationMessage = `🌐 Signing in from afar (${this.formatDistance(distance)} away)`;
                        $wire.setPresenceType('online');
                    }
                },
                (error) => {
                    this.locationStatus = 'error';
                    // Default to online when location detection fails
                    $wire.setPresenceType('online');

                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            this.locationMessage = '🌐 Signing in remotely (location access denied)';
                            break;
                        case error.POSITION_UNAVAILABLE:
                            this.locationMessage = '🌐 Location unavailable - signing in remotely';
                            break;
                        case error.TIMEOUT:
                            this.locationMessage = '🌐 Location request timed out - signing in remotely';
                            break;
                        default:
                            this.locationMessage = '🌐 Unable to detect location - signing in remotely';
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
            title="Thank you for signing our guestbook!"
            description="Your entry has been recorded. We appreciate your visit!"
            icon="o-check-circle"
            class="alert-success"
            dismissible
            wire:click="hideSuccess"
        />
    @endif

    {{-- Form Card --}}
    @if(!$showSuccess)
        @if($eventConfig)
            <x-card title="Sign the Guestbook" subtitle="Welcome! Please sign our event guestbook.">
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
                            icon="o-exclamation-triangle"
                            class="alert-error"
                            dismissible
                        />
                    @enderror

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {{-- Name --}}
                        <x-input
                            label="Name"
                            wire:model="name"
                            icon="o-user"
                            placeholder="Your full name"
                            required
                        />

                        {{-- Callsign --}}
                        <x-input
                            label="Callsign"
                            wire:model="callsign"
                            icon="o-signal"
                            placeholder="Optional - e.g., W1AW"
                            hint="Your amateur radio callsign (if applicable)"
                        />
                    </div>

                    {{-- Email --}}
                    <x-input
                        label="Email"
                        wire:model="email"
                        type="email"
                        icon="o-envelope"
                        placeholder="Optional - your@email.com"
                        hint="We'll only use this to contact you about the event"
                    />

                    {{-- Visitor Category --}}
                    <x-select
                        label="I am visiting as"
                        wire:model="visitor_category"
                        :options="$this->visitorCategories"
                        option-value="id"
                        option-label="name"
                        icon="o-user-group"
                        required
                    />

                    {{-- Presence Type --}}
                    <div class="space-y-2">
                        <x-radio
                            label="How are you visiting?"
                            wire:model="presence_type"
                            :options="$this->presenceTypes"
                            option-value="id"
                            option-label="name"
                        />

                        {{-- Location detection hint --}}
                        @if($this->hasEventLocation)
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
                                    <x-icon name="o-map-pin" class="w-4 h-4" />
                                </template>
                                <template x-if="locationStatus === 'online'">
                                    <x-icon name="o-globe-alt" class="w-4 h-4" />
                                </template>
                                <template x-if="locationStatus === 'error' || locationStatus === 'unsupported'">
                                    <x-icon name="o-exclamation-circle" class="w-4 h-4" />
                                </template>
                                <span x-text="locationMessage"></span>
                            </div>

                            <button
                                type="button"
                                x-show="locationStatus !== 'detecting'"
                                @click="detectLocation()"
                                class="btn btn-xs btn-ghost"
                            >
                                <x-icon name="o-arrow-path" class="w-3 h-3" />
                                Re-detect location
                            </button>
                        @endif
                    </div>

                    {{-- Comments --}}
                    <x-textarea
                        label="Comments"
                        wire:model="comments"
                        placeholder="Optional - Share your thoughts about the event!"
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
                            icon="o-pencil-square"
                            spinner="save"
                        />
                    </div>
                </form>
            </x-card>
        @else
            {{-- No active event with guestbook enabled --}}
            <x-alert
                title="Guestbook Not Available"
                description="There is no active event with the guestbook enabled at this time."
                icon="o-information-circle"
                class="alert-info"
            />
        @endif
    @else
        {{-- After success, show option to add another entry --}}
        <x-card class="text-center">
            <div class="py-8 space-y-4">
                <x-icon name="o-check-circle" class="w-16 h-16 mx-auto text-success" />
                <h3 class="text-xl font-semibold">Thank You!</h3>
                <p class="text-base-content/70">Your guestbook entry has been recorded.</p>
                <x-button
                    label="Sign Again"
                    wire:click="hideSuccess"
                    class="btn-primary"
                    icon="o-plus"
                />
            </div>
        </x-card>
    @endif
</div>
