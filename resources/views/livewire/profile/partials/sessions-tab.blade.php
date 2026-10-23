{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="space-y-6">
    {{-- Current Session --}}
    <div class="card bg-base-100 shadow border-2 border-primary">
        <div class="card-body">
            <div class="flex items-center gap-2">
                <x-badge value="Current Session" class="badge-success gap-1">
                    <x-slot:prepend>
                        <span class="inline-block w-2 h-2 bg-success rounded-full animate-pulse"></span>
                    </x-slot:prepend>
                </x-badge>
            </div>

            <div class="mt-2 space-y-1">
                <p class="text-sm">
                    <span class="font-semibold">Device:</span> This device
                </p>
                <p class="text-sm">
                    <span class="font-semibold">IP Address:</span> {{ request()->ip() }}
                </p>
                <p class="text-sm">
                    <span class="font-semibold">Last Account Sign-In:</span> {{ $user->last_login_at?->format('F j, Y g:i A') ?? 'Unknown' }}
                </p>
            </div>
        </div>
    </div>

    {{-- Other Sessions --}}
    @if($sessions->count() > 1)
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Other Sign-In Sessions</h3>
                <p class="text-sm text-gray-500">
                    You have {{ $sessions->count() - 1 }} other {{ str('session')->plural($sessions->count() - 1) }}.
                </p>

                <div class="space-y-4 mt-4">
                    @foreach($sessions as $session)
                        @if($session->id !== session()->getId())
                            <div class="flex items-center justify-between p-4 border rounded">
                                <div class="space-y-1">
                                    <p class="text-sm">
                                        <span class="font-semibold">IP Address:</span> {{ $session->ip_address }}
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        Last active: {{ \Carbon\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}
                                    </p>
                                </div>
                                <x-button class="btn-outline btn-sm" wire:click="revokeSession('{{ $session->id }}')">
                                    Sign Out
                                </x-button>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Logout All Other Sessions --}}
        <div class="card bg-base-100 shadow">
            <div class="card-body">
                <h3 class="card-title">Sign Out Other Sessions</h3>
                <p class="text-sm text-gray-500">
                    Sign out of all other browsers and devices. This session will stay signed in.
                </p>

                <div class="mt-4">
                    <x-input
                        label="Current Password"
                        type="password"
                        wire:model="current_password"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                </div>

                <div class="card-actions justify-end mt-4">
                    <x-button
                        class="btn-error"
                        wire:click="logoutOtherSessions"
                        spinner="logoutOtherSessions"
                    >
                        Sign Out Other Sessions
                    </x-button>
                </div>
            </div>
        </div>
    @else
        <x-alert icon="phosphor-info">
            No other sign-in sessions are listed.
        </x-alert>
    @endif
</div>
