{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="space-y-6">
    <x-slot:title>Participation Interest{{ $this->event ? ' - ' . $this->event->name : '' }}</x-slot:title>

    {{-- Header --}}
    <x-header use-h1
        title="Count Me In"
        :subtitle="$this->event ? $this->event->name . ' · ' . toLocalTime($this->event->start_time)->format('M j, Y') : null"
        separator
        progress-indicator
    />

    @if(! $this->event)
        <x-alert icon="phosphor-info" class="alert-info">
            <strong class="block">Not taking responses right now</strong>
            Check back closer to the next event.
        </x-alert>
    @elseif($submitted)
        <x-card>
            <div class="py-4 space-y-4 text-center">
                <x-icon name="phosphor-check-circle" class="w-14 h-14 mx-auto text-success" />
                <h2 class="text-xl font-semibold">
                    {{ $updatedExisting ? 'Your response is updated' : 'Thanks for letting us know' }}
                </h2>
                <p class="text-base-content/70">
                    We saved your response under {{ $email }}.
                </p>

                @if($this->canRegister)
                    <p>You'll need an account to sign up for shifts. Make one now and your details are already filled in.</p>
                    <x-button
                        label="Create an Account"
                        icon="phosphor-user-plus"
                        class="btn-primary"
                        link="{{ route('register') }}"
                    />
                @elseif($this->hasAccount)
                    <p>
                        You already have an account with this email.
                        <a href="{{ route('login') }}" class="link link-primary">Sign in</a>
                        to sign up for shifts.
                    </p>
                @endif

                <div>
                    <x-button
                        label="Change My Response"
                        icon="phosphor-pencil-simple"
                        class="btn-ghost"
                        wire:click="editResponse"
                    />
                </div>
            </div>
        </x-card>
    @else
        <x-alert icon="phosphor-info" class="alert-info">
            Shift sign-ups aren't open yet. Let the organizers know you plan to participate, on the air or helping set up, and roughly when you're free. To change your answers later, send the form again with the same email.
        </x-alert>

        <form wire:submit="save">
            {{-- Honeypot field: hidden from people, bots fill it in --}}
            <div class="hidden" aria-hidden="true">
                <label for="interest-website">Website</label>
                <input type="text" id="interest-website" wire:model="honeypot" tabindex="-1" autocomplete="off" />
            </div>

            @error('form')
                <x-alert icon="phosphor-warning" class="alert-error mb-6">{{ $message }}</x-alert>
            @enderror

            {{-- About You Card --}}
            <x-card class="mb-6">
                <x-slot:title>About You</x-slot:title>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input
                        label="First Name"
                        wire:model="firstName"
                        autocomplete="given-name"
                        autocapitalize="words"
                        icon="phosphor-user"
                        required
                    />

                    <x-input
                        label="Last Name"
                        wire:model="lastName"
                        autocomplete="family-name"
                        autocapitalize="words"
                        icon="phosphor-user"
                        required
                    />

                    <x-input
                        label="Call Sign"
                        wire:model="callsign"
                        autocomplete="off"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        icon="phosphor-megaphone"
                        placeholder="e.g., W1AW"
                    />

                    <x-select
                        label="License Class"
                        wire:model="licenseClass"
                        :options="$this->licenseClasses"
                        option-value="value"
                        option-label="label"
                        placeholder="Choose one"
                        icon="phosphor-identification-card"
                    />

                    <div class="md:col-span-2">
                        <x-input
                            label="Email"
                            wire:model="email"
                            type="email"
                            autocomplete="email"
                            icon="phosphor-envelope"
                            placeholder="you@example.com"
                            hint="Organizers can use this to contact you."
                            required
                        />
                    </div>
                </div>
            </x-card>

            {{-- Availability Card --}}
            <x-card class="mb-6">
                <x-slot:title>Availability</x-slot:title>

                <fieldset class="fieldset py-0">
                    <legend class="fieldset-legend mb-0.5">When are you free? <span class="text-error">*</span></legend>
                    <p class="fieldset-label">Pick at least one time that might work. You can choose more than one.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4">
                        @foreach($this->availabilityOptions as $block)
                            <x-checkbox
                                value="{{ $block->value }}"
                                wire:model="availability"
                                :label="$block->label()"
                                :hint="$block->hint()"
                                omit-error
                            />
                        @endforeach
                    </div>
                    @error('availability')
                        <div class="text-error">{{ $message }}</div>
                    @enderror
                </fieldset>

                <x-textarea
                    label="Notes"
                    wire:model="notes"
                    rows="3"
                    maxlength="1000"
                    placeholder="What would you like to do? For example, operate CW or phone, log for an operator, run the GOTA station, or help with antennas."
                />
            </x-card>

            {{-- Form Actions --}}
            <div class="flex gap-3">
                <x-button
                    label="Send"
                    type="submit"
                    icon="phosphor-paper-plane-tilt"
                    class="btn-primary"
                    spinner="save"
                />
            </div>
        </form>
    @endif
</div>
