<div class="space-y-6">
    {{-- Header --}}
    <x-header
        title="{{ $isEditing ? 'Edit Message' : 'Log Message' }}"
        subtitle="{{ $isEditing ? 'Update message details' : ($ics213Enabled ? 'Enter a new radiogram or ICS-213 message' : 'Enter a new radiogram message') }}"
        separator
        progress-indicator
    >
        <x-slot:actions>
            @if(\Illuminate\Support\Facades\Route::has('events.messages.index'))
                <x-button
                    label="Back to Messages"
                    icon="o-arrow-left"
                    class="btn-ghost"
                    link="{{ route('events.messages.index', $event) }}"
                    wire:navigate
                />
            @endif
        </x-slot:actions>
    </x-header>

    <form wire:submit="save" class="space-y-6">

        {{-- Format & Role --}}
        <x-card>
            <x-slot:title>Message Type</x-slot:title>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if($ics213Enabled)
                    <x-select
                        label="Format"
                        wire:model.live="format"
                        :options="[
                            ['id' => 'radiogram', 'name' => 'ARRL Radiogram'],
                            ['id' => 'ics213', 'name' => 'ICS-213'],
                        ]"
                        option-value="id"
                        option-label="name"
                        icon="o-document-text"
                        required
                    />
                @endif

                <x-select
                    label="Role"
                    wire:model="role"
                    :options="[
                        ['id' => 'originated', 'name' => 'Originated'],
                        ['id' => 'relayed', 'name' => 'Relayed'],
                        ['id' => 'received_delivered', 'name' => 'Received & Delivered'],
                    ]"
                    option-value="id"
                    option-label="name"
                    icon="o-arrow-path"
                    required
                />
            </div>
        </x-card>

        {{-- Message Details (shared) --}}
        <x-card>
            <x-slot:title>Message Details</x-slot:title>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input
                    label="Message Number"
                    wire:model="messageNumber"
                    type="number"
                    min="1"
                    icon="o-hashtag"
                    placeholder="e.g., 1"
                    required
                />

                <x-flatpickr
                    label="Filed At"
                    wire:model="filedAt"
                    icon="o-clock"
                />
            </div>
        </x-card>

        {{-- Radiogram: Preamble --}}
        @if($format === 'radiogram')
            <x-card>
                <x-slot:title>Preamble</x-slot:title>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <x-select
                        label="Precedence"
                        wire:model="precedence"
                        :options="[
                            ['id' => 'routine', 'name' => 'R - Routine'],
                            ['id' => 'welfare', 'name' => 'W - Welfare'],
                            ['id' => 'priority', 'name' => 'P - Priority'],
                            ['id' => 'emergency', 'name' => 'EMERGENCY'],
                        ]"
                        option-value="id"
                        option-label="name"
                        icon="o-flag"
                        required
                    />

                    <x-select
                        label="HX Code (Optional)"
                        wire:model="hxCode"
                        :options="[
                            ['id' => '', 'name' => 'None'],
                            ['id' => 'hxa', 'name' => 'HXA - Collect delivery authorized'],
                            ['id' => 'hxb', 'name' => 'HXB - Cancel if not delivered in time'],
                            ['id' => 'hxc', 'name' => 'HXC - Report delivery date/time'],
                            ['id' => 'hxd', 'name' => 'HXD - Report origin station and delivery'],
                            ['id' => 'hxe', 'name' => 'HXE - Get reply from addressee'],
                            ['id' => 'hxf', 'name' => 'HXF - Hold delivery until date'],
                            ['id' => 'hxg', 'name' => 'HXG - No toll delivery required'],
                        ]"
                        option-value="id"
                        option-label="name"
                        icon="o-tag"
                        placeholder="None"
                    />

                    <x-input
                        label="Station of Origin"
                        wire:model="stationOfOrigin"
                        icon="o-signal"
                        placeholder="e.g., W1TEST"
                        maxlength="20"
                        required
                    />

                    <x-input
                        label="Check (Word Count)"
                        wire:model="checkCount"
                        icon="o-calculator"
                        placeholder="Auto-calculated from message text"
                        hint="Automatically counted from message text"
                        required
                    />

                    <x-input
                        label="Place of Origin"
                        wire:model="placeOfOrigin"
                        icon="o-map-pin"
                        placeholder="e.g., Hartford, CT"
                        required
                    />
                </div>
            </x-card>
        @endif

        {{-- Radiogram: Addressee --}}
        @if($format === 'radiogram')
            <x-card>
                <x-slot:title>Addressee</x-slot:title>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input
                        label="Addressee Name"
                        wire:model="addresseeName"
                        icon="o-user"
                        placeholder="Full name"
                        required
                    />

                    <x-input
                        label="Phone"
                        wire:model="addresseePhone"
                        icon="o-phone"
                        placeholder="Optional"
                        maxlength="30"
                    />

                    <x-input
                        label="Street Address"
                        wire:model="addresseeAddress"
                        icon="o-home"
                        placeholder="Optional"
                    />

                    <x-input
                        label="City"
                        wire:model="addresseeCity"
                        icon="o-building-office"
                        placeholder="Optional"
                    />

                    <x-input
                        label="State"
                        wire:model="addresseeState"
                        icon="o-map"
                        placeholder="e.g., CT"
                        maxlength="10"
                    />

                    <x-input
                        label="ZIP Code"
                        wire:model="addresseeZip"
                        icon="o-envelope"
                        placeholder="Optional"
                        maxlength="20"
                    />
                </div>
            </x-card>
        @endif

        {{-- ICS-213: To --}}
        @if($format === 'ics213')
            <x-card>
                <x-slot:title>To</x-slot:title>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input
                        label="Name"
                        wire:model="addresseeName"
                        icon="o-user"
                        placeholder="Recipient name"
                        required
                    />

                    <x-input
                        label="Position/Title"
                        wire:model="icsToPosition"
                        icon="o-briefcase"
                        placeholder="e.g., Operations Chief"
                    />
                </div>
            </x-card>

            {{-- ICS-213: From --}}
            <x-card>
                <x-slot:title>From</x-slot:title>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input
                        label="Name"
                        wire:model="signature"
                        icon="o-user"
                        placeholder="Sender name"
                        required
                    />

                    <x-input
                        label="Position/Title"
                        wire:model="icsFromPosition"
                        icon="o-briefcase"
                        placeholder="e.g., Planning Section Chief"
                    />
                </div>
            </x-card>

            {{-- ICS-213: Subject --}}
            <x-card>
                <x-slot:title>Subject</x-slot:title>

                <x-input
                    label="Subject"
                    wire:model="icsSubject"
                    icon="o-chat-bubble-left"
                    placeholder="Message subject"
                    required
                />
            </x-card>
        @endif

        {{-- Message Text --}}
        <x-card>
            <x-slot:title>Message Text</x-slot:title>

            <x-textarea
                label="Message Text"
                wire:model.live.debounce.300ms="messageText"
                placeholder="{{ $format === 'radiogram' ? 'Enter message text using X for periods, XX for paragraph breaks...' : 'Enter message text' }}"
                rows="6"
                required
                hint="{{ $format === 'radiogram' ? 'Word count is auto-calculated as the Check field' : '' }}"
            />

            @if($format === 'radiogram' && $checkCount)
                <div class="mt-2">
                    <x-badge value="Word count: {{ $checkCount }}" class="badge-info" />
                </div>
            @endif
        </x-card>

        {{-- Radiogram: Signature & Notes --}}
        @if($format === 'radiogram')
            <x-card>
                <x-slot:title>Signature & Notes</x-slot:title>

                <div class="space-y-4">
                    <x-input
                        label="Signature"
                        wire:model="signature"
                        icon="o-pencil"
                        placeholder="Sender's name"
                        required
                    />

                    <div class="space-y-2">
                        <x-toggle
                            label="SM/SEC Message"
                            wire:model.live="isSmMessage"
                            hint="Mark as the official Section Manager / Section Emergency Coordinator message"
                        />

                        @if($isSmMessage)
                            <x-alert icon="o-exclamation-triangle" class="alert-warning">
                                Only one SM/SEC message may exist per event. This message will be marked as the official SM/SEC radiogram.
                            </x-alert>
                        @endif

                        @error('isSmMessage')
                            <x-alert icon="o-x-circle" class="alert-error">
                                {{ $errors->first('isSmMessage') }}
                            </x-alert>
                        @enderror
                    </div>

                    <x-textarea
                        label="Notes"
                        wire:model="notes"
                        placeholder="Internal notes (not part of the message)"
                        rows="3"
                    />
                </div>
            </x-card>
        @endif

        {{-- ICS-213: Additional --}}
        @if($format === 'ics213')
            <x-card>
                <x-slot:title>Additional</x-slot:title>

                <div class="space-y-4">
                    <div class="space-y-2">
                        <x-toggle
                            label="SM/SEC Message"
                            wire:model.live="isSmMessage"
                            hint="Mark as the official Section Manager / Section Emergency Coordinator message"
                        />

                        @if($isSmMessage)
                            <x-alert icon="o-exclamation-triangle" class="alert-warning">
                                Only one SM/SEC message may exist per event.
                            </x-alert>
                        @endif

                        @error('isSmMessage')
                            <x-alert icon="o-x-circle" class="alert-error">
                                {{ $errors->first('isSmMessage') }}
                            </x-alert>
                        @enderror
                    </div>

                    <x-textarea
                        label="Notes"
                        wire:model="notes"
                        placeholder="Internal notes (not part of the message)"
                        rows="3"
                    />
                </div>
            </x-card>
        @endif

        {{-- Radiogram: Routing --}}
        @if($format === 'radiogram')
            <x-card>
                <x-slot:title>Routing</x-slot:title>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input
                        label="Sent To"
                        wire:model="sentTo"
                        icon="o-paper-airplane"
                        placeholder="Callsign (optional)"
                        maxlength="20"
                        hint="Station this message was sent to"
                    />

                    <x-input
                        label="Received From"
                        wire:model="receivedFrom"
                        icon="o-inbox"
                        placeholder="Callsign (optional)"
                        maxlength="20"
                        hint="Station this message was received from"
                    />
                </div>
            </x-card>
        @endif

        {{-- ICS-213: Reply --}}
        @if($format === 'ics213')
            <x-card>
                <x-slot:title>Reply</x-slot:title>

                <div class="space-y-4">
                    <x-textarea
                        label="Reply"
                        wire:model="icsReplyText"
                        placeholder="Reply text (if applicable)"
                        rows="4"
                    />

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <x-input
                            label="Replier Name"
                            wire:model="icsReplyName"
                            icon="o-user"
                            placeholder="Name"
                        />

                        <x-input
                            label="Replier Position/Title"
                            wire:model="icsReplyPosition"
                            icon="o-briefcase"
                            placeholder="Position"
                        />

                        <x-flatpickr
                            label="Reply Date/Time"
                            wire:model="icsReplyDate"
                            icon="o-clock"
                        />
                    </div>
                </div>
            </x-card>
        @endif

        {{-- Actions --}}
        <div class="flex gap-3">
            @if(\Illuminate\Support\Facades\Route::has('events.messages.index'))
                <x-button
                    label="Cancel"
                    icon="o-x-mark"
                    class="btn-ghost"
                    link="{{ route('events.messages.index', $event) }}"
                    wire:navigate
                />
            @endif

            <x-button
                label="{{ $isEditing ? 'Update Message' : 'Save Message' }}"
                type="submit"
                class="btn-primary"
                icon="o-check"
                spinner="save"
            />
        </div>

    </form>
</div>
