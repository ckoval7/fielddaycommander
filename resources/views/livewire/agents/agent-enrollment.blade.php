{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="space-y-6">
    <x-slot:title>Agents</x-slot:title>

    <x-header use-h1 title="Agents" subtitle="Field Day Commander Agent sends contacts from logging software on another computer to this server. This separate desktop program has not been released yet." separator progress-indicator />

    @unless ($isSecure)
        <x-alert icon="phosphor-lock-open" class="alert-warning" title="HTTPS required for agents">
            Open this page over HTTPS to create a code. Ask your administrator to set up HTTPS if needed. Behind a TLS proxy, the proxy must be in TRUSTED_PROXIES.
        </x-alert>
    @endunless

    @if ($issuedCode)
        <x-card title="Enter this code in the agent" wire:poll.5s="checkIssuedCode">
            <dl class="space-y-4">
                <div>
                    <dt class="text-sm text-base-content/70">Server address</dt>
                    <dd class="font-mono break-all select-all">{{ $serverUrl }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-base-content/70">Enrollment code</dt>
                    <dd class="font-mono text-2xl font-bold break-all select-all" data-test="enrollment-code">{{ $issuedCode }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-base-content/70">The code works once and expires in {{ \App\Services\AgentEnrollmentService::CODE_LIFETIME_MINUTES }} minutes. It is for the current event and won't be shown again.</p>
            <x-slot:actions>
                <x-button label="Done" class="max-sm:w-full" wire:click="dismissCode" />
            </x-slot:actions>
        </x-card>
    @elseif ($isSecure)
        <x-card title="Enroll an agent" subtitle="{{ $eventName ? 'Contacts will go to '.$eventName.'.' : '' }}">
            <form wire:submit="issue" class="space-y-4">
                <x-input
                    label="Agent name"
                    wire:model="name"
                    maxlength="50"
                    placeholder="Phone tent laptop"
                    :hint="$canCreateStations
                        ? 'Used as the station name when a contact does not name one.'
                        : 'Used when a contact does not name a station. The station must already exist.'"
                    autocomplete="off"
                    enterkeyhint="done"
                />
                <x-button type="submit" label="Create Code" icon="phosphor-key" class="btn-primary max-sm:w-full" spinner="issue" />
            </form>
        </x-card>
    @endif

    <x-card title="Enrolled agents">
        @forelse ($agents as $agent)
            <div wire:key="agent-{{ $agent->id }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3 border-b border-base-300 last:border-b-0">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-medium break-words">{{ $agent->name }}</span>
                        @switch ($agent->status())
                            @case (\App\Models\Agent::STATUS_ENROLLED)
                                <x-badge value="Enrolled" icon="phosphor-check-circle" class="badge-success" />
                                @break
                            @case (\App\Models\Agent::STATUS_WAITING)
                                <x-badge value="Waiting to enroll" icon="phosphor-hourglass" class="badge-warning" />
                                @break
                            @default
                                <x-badge value="Revoked" icon="phosphor-prohibit" class="badge-neutral" />
                        @endswitch
                    </div>
                    <p class="mt-1 text-sm text-base-content/70">
                        @if ($agent->last_seen_at)
                            Last seen {{ $agent->last_seen_at->diffForHumans() }}
                            @if ($agent->agent_version)
                                · version {{ $agent->agent_version }}
                            @endif
                        @endif
                        @if ($agent->createdBy)
                            <span class="block">Added by {{ $agent->createdBy->call_sign }}</span>
                        @endif
                    </p>
                </div>
                @if ($agent->revoked_at === null)
                    @can('revoke', $agent)
                        <x-button
                            label="Revoke"
                            icon="phosphor-prohibit"
                            class="btn-outline btn-error btn-sm max-sm:w-full"
                            wire:click="revoke({{ $agent->id }})"
                            wire:confirm="Revoke {{ $agent->name }}? This server will stop accepting its contacts and any unused code will stop working."
                            spinner="revoke({{ $agent->id }})"
                        />
                    @endcan
                @endif
            </div>
        @empty
            <p class="text-base-content/70">No agents to show for this event.</p>
        @endforelse
    </x-card>
</div>
