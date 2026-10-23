{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="max-w-md mx-auto">
    @php $device = $this->device; @endphp

    @if($device)
        <div class="text-center mb-6">
            <div class="text-xs uppercase tracking-wide text-base-content/60">Station Mode</div>
            <h1 class="text-2xl font-bold mt-1">{{ $device->station?->name }}</h1>
            @if($device->station?->isUnavailable())
                <x-alert icon="phosphor-prohibit" class="alert-warning text-sm mt-4">
                    This station is marked unavailable ({{ $device->station->unavailable_reason->label() }}). You can still sign in to view the log.
                </x-alert>
            @endif
        </div>

        @if($this->canExitAsCurrentUser)
            <p class="text-sm text-center mb-4">
                You are signed in as <span class="font-semibold">{{ auth()->user()->call_sign }}</span>.
                Sign out to let operators use this station, or take this browser out of station mode.
            </p>
            <div class="flex flex-col sm:flex-row gap-2">
                <form method="POST" action="{{ route('logout') }}" class="flex-1 flex" data-navigate-ignore>
                    @csrf
                    <x-button label="Sign Out" type="submit" class="btn-outline flex-1" icon="phosphor-sign-out" />
                </form>
                <x-button label="Exit Station Mode" wire:click="exitStationMode" wire:confirm="Take this browser out of station mode?" class="btn-error flex-1" icon="phosphor-power" spinner="exitStationMode" />
            </div>
        @else
            @if($device->station?->is_gota)
                <x-alert icon="phosphor-graduation-cap" class="alert-info text-sm mb-4" data-gota-instructions>
                    <div>
                        <div class="font-semibold">GOTA station: control operator signs in</div>
                        <p class="mt-1">The licensed control operator (GOTA coach) starts the session with their own callsign and stays with the station. Enter each GOTA operator on the logging screen, not here.</p>
                    </div>
                </x-alert>
            @endif

            <form wire:submit="start" class="space-y-4">
                <x-input
                    :label="$device->station?->is_gota ? 'Control operator callsign' : 'Your callsign'"
                    wire:model.live.debounce.400ms="callSign"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    icon="phosphor-identification-card"
                    class="uppercase"
                    autocomplete="off"
                    autofocus
                    maxlength="12"
                />

                @if($needsName)
                    <p class="text-sm text-base-content/70">We don't have a name for this callsign yet. Please enter yours to continue.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-input label="First name" wire:model="firstName" autocomplete="off" maxlength="50" autocapitalize="words" />
                        <x-input label="Last name" wire:model="lastName" autocomplete="off" maxlength="50" autocapitalize="words" />
                    </div>
                    <label class="flex items-center gap-3 cursor-pointer py-2">
                        <input type="checkbox" class="checkbox checkbox-sm" wire:model="isYouth" />
                        <span class="text-sm">Youth (age 18 or younger)</span>
                    </label>
                @endif

                <x-button label="Start Logging" type="submit" class="btn-primary w-full" icon="phosphor-play" spinner="start" />
            </form>

            <div class="text-center mt-6 pt-4 border-t border-base-300">
                <button type="button" wire:click="openExitModal" class="link link-hover text-xs text-base-content/60">
                    Station captain: exit station mode
                </button>
            </div>

            <x-modal wire:model="showExitModal" title="Exit Station Mode" persistent>
                <div class="space-y-4">
                    <p class="text-sm">A station captain or administrator must enter their email and password to exit station mode.</p>
                    <x-input label="Email" type="email" wire:model="exitEmail" autocomplete="off" icon="phosphor-envelope" />
                    <x-input label="Password" type="password" wire:model="exitPassword" autocomplete="off" icon="phosphor-lock" />
                </div>

                <x-slot:actions>
                    <x-button label="Cancel" wire:click="cancelExit" class="w-full sm:w-auto" />
                    <x-button label="Exit Station Mode" wire:click="exitStationMode" class="btn-error w-full sm:w-auto" icon="phosphor-power" spinner="exitStationMode" />
                </x-slot:actions>
            </x-modal>
        @endif
    @endif
</div>
