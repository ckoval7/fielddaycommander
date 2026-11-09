{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="space-y-6">
    <x-slot:title>Relays</x-slot:title>

    <x-header use-h1 title="Relays" subtitle="Field Day Commander Relay sends contacts from logging software on another computer to this server." separator progress-indicator />

    <x-demo-relay-download />

    @unless ($isSecure)
        <x-alert icon="phosphor-lock-open" class="alert-warning">
            <strong class="block">HTTPS required for relays</strong>
            Open this page over HTTPS to create a code. Ask your administrator to set up HTTPS if needed. Behind a TLS proxy, the proxy must be in TRUSTED_PROXIES.
        </x-alert>
    @endunless

    @if ($issuedCode)
        <x-card title="Enter this code in the relay" wire:poll.5s="checkIssuedCode">
            <dl class="space-y-4">
                <div>
                    <dt class="text-sm text-base-content/70">Server address</dt>
                    <dd class="font-mono break-all select-all">{{ $serverUrl }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-base-content/70">Enrollment code</dt>
                    <dd x-data="{ copied: false, failed: false }">
                        <div class="flex items-center gap-2">
                            <span class="min-w-0 font-mono text-2xl font-bold break-all select-all" data-test="enrollment-code">{{ $issuedCode }}</span>
                            <button
                                type="button"
                                class="btn btn-ghost btn-square shrink-0 touch-manipulation"
                                @click="if (await $copy(@js($issuedCode))) { copied = true; failed = false; setTimeout(() => copied = false, 2000) } else { failed = true; setTimeout(() => failed = false, 4000) }"
                                aria-label="Copy enrollment code"
                                :title="copied ? 'Copied' : (failed ? 'Could not copy' : 'Copy code')"
                                data-test="copy-enrollment-code"
                            >
                                <x-icon x-show="!copied" name="phosphor-clipboard" class="w-5 h-5" />
                                <x-icon x-show="copied" x-cloak name="phosphor-check" class="w-5 h-5 text-success" />
                            </button>
                        </div>
                        <output x-show="copied" x-cloak class="block text-sm text-success">Copied.</output>
                        <output x-show="failed" x-cloak class="block text-sm text-error">Could not copy automatically. Select the code above and copy it.</output>
                    </dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-base-content/70">The code works once and expires in {{ \App\Services\RelayEnrollmentService::CODE_LIFETIME_MINUTES }} minutes. It is for the current event and won't be shown again.</p>
            <x-slot:actions>
                <x-button label="Done" class="max-sm:w-full" wire:click="dismissCode" />
            </x-slot:actions>
        </x-card>
    @elseif ($isSecure)
        <x-card title="Enroll a relay" subtitle="{{ $eventName ? 'Contacts will go to '.$eventName.'.' : '' }}">
            <form wire:submit="issue" class="space-y-4">
                <x-input
                    label="Relay name"
                    wire:model="name"
                    maxlength="50"
                    placeholder="Phone tent laptop"
                    :hint="$canCreateStations
                        ? 'Contacts without a station use this name. If no station matches, one is created.'
                        : 'Contacts without a station use this name. Match a station name or hostname, or contacts wait in the queue until someone creates it.'"
                    autocomplete="off"
                    enterkeyhint="done"
                />
                <x-button type="submit" label="Create Code" icon="phosphor-key" class="btn-primary max-sm:w-full" spinner="issue" />
            </form>
        </x-card>
    @endif

    <x-card title="Enrolled relays">
        @forelse ($relays as $relay)
            <div wire:key="relay-{{ $relay->id }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3 border-b border-base-300 last:border-b-0">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-medium break-words">{{ $relay->name }}</span>
                        @switch ($relay->status())
                            @case (\App\Models\Relay::STATUS_ENROLLED)
                                <x-badge value="Enrolled" icon="phosphor-check-circle" class="badge-success" />
                                @break
                            @case (\App\Models\Relay::STATUS_WAITING)
                                <x-badge value="Waiting to enroll" icon="phosphor-hourglass" class="badge-warning" />
                                @break
                            @default
                                <x-badge value="Revoked" icon="phosphor-prohibit" class="badge-neutral" />
                        @endswitch
                    </div>
                    <p class="mt-1 text-sm text-base-content/70">
                        @if ($relay->last_seen_at)
                            Last seen {{ $relay->last_seen_at->diffForHumans() }}
                            @if ($relay->relay_version)
                                · version {{ $relay->relay_version }}
                            @endif
                        @endif
                        @if ($relay->createdBy)
                            <span class="block">Added by {{ $relay->createdBy->call_sign }}</span>
                        @endif
                    </p>
                </div>
                @if ($relay->revoked_at === null)
                    @can('revoke', $relay)
                        <x-button
                            label="Revoke"
                            icon="phosphor-prohibit"
                            class="btn-outline btn-error btn-sm max-sm:w-full"
                            wire:click="revoke({{ $relay->id }})"
                            wire:confirm="Revoke {{ $relay->name }}? This server will stop accepting its contacts and any unused code will stop working."
                            spinner="revoke({{ $relay->id }})"
                        />
                    @endcan
                @endif
            </div>
        @empty
            <p class="text-base-content/70">No relays to show for this event.</p>
        @endforelse
    </x-card>
</div>
