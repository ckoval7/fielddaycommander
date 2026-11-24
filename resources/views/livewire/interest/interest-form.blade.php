{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    <x-slot:title>Participation Interest{{ $this->event ? ' - ' . $this->event->name : '' }}</x-slot:title>

    <div class="p-4 md:p-6 max-sm:px-0 max-w-2xl mx-auto">
        <div class="mb-6">
            <h1 class="text-2xl md:text-3xl font-bold">Count me in</h1>
            @if($this->event)
                <p class="text-base-content/70 mt-1">
                    {{ $this->event->name }} &middot; {{ toLocalTime($this->event->start_time)->format('M j, Y') }}
                </p>
            @endif
        </div>

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
                        <div class="rounded-box bg-base-200 p-4 max-sm:p-3 text-left space-y-3">
                            <p>
                                You'll need an account to sign up for shifts. Make one now and your details are already filled in.
                            </p>
                            <x-button
                                label="Create an Account"
                                icon="phosphor-user-plus"
                                class="btn-primary w-full sm:w-auto"
                                link="{{ route('register') }}"
                            />
                        </div>
                    @elseif($this->hasAccount)
                        <p>
                            You already have an account with this email.
                            <a href="{{ route('login') }}" class="link link-primary">Sign in</a>
                            to sign up for shifts.
                        </p>
                    @endif

                    <x-button
                        label="Change My Response"
                        icon="phosphor-pencil-simple"
                        class="btn-ghost"
                        wire:click="editResponse"
                    />
                </div>
            </x-card>
        @else
            <x-card>
                <p class="text-base-content/70 mb-4">
                    Shift sign-ups aren't open yet. Let the organizers know you plan to participate, on the air or helping set up, and roughly when you're free. To change your answers later, send the form again with the same email.
                </p>

                <form wire:submit="save" class="space-y-4">
                    <div class="hidden" aria-hidden="true">
                        <label for="interest-website">Website</label>
                        <input type="text" id="interest-website" wire:model="honeypot" tabindex="-1" autocomplete="off" />
                    </div>

                    @error('form')
                        <x-alert icon="phosphor-warning" class="alert-error">{{ $message }}</x-alert>
                    @enderror

                    <x-input
                        label="Name"
                        wire:model="name"
                        autocomplete="name"
                        autocapitalize="words"
                        icon="phosphor-user"
                        required
                    />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input
                            label="Call sign (optional)"
                            wire:model="callsign"
                            autocomplete="off"
                            autocapitalize="characters"
                            autocorrect="off"
                            spellcheck="false"
                            icon="phosphor-megaphone"
                            placeholder="For example, W1AW"
                        />

                        <x-select
                            label="License class (optional)"
                            wire:model="license_class"
                            :options="$this->licenseClasses"
                            option-value="value"
                            option-label="label"
                            placeholder="Choose one"
                            icon="phosphor-identification-card"
                        />
                    </div>

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

                    <fieldset class="fieldset py-0">
                        <legend class="fieldset-legend mb-0.5">When are you free? <span class="text-error">*</span></legend>
                        <p class="fieldset-label">Pick at least one time that might work. You can choose more than one.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4">
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
                        label="Anything else? (optional)"
                        wire:model="notes"
                        rows="3"
                        maxlength="1000"
                        placeholder="What would you like to do? For example, operate CW or phone, log for an operator, run the GOTA station, or help with antennas."
                    />

                    <div class="pt-2">
                        <x-button
                            label="Send"
                            type="submit"
                            icon="phosphor-paper-plane-tilt"
                            class="btn-primary w-full sm:w-auto"
                            spinner="save"
                        />
                    </div>
                </form>
            </x-card>
        @endif
    </div>
</div>
