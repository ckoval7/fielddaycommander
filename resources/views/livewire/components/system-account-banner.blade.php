{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    @if($isSystemUser && $openedBy !== null)
        <div class="alert bg-amber-100 border-l-4 border-amber-500 text-amber-900 shadow-md rounded-none" role="alert">
            <div class="flex items-start sm:items-center gap-3 w-full">
                <x-icon name="phosphor-key" class="w-5 h-5 text-amber-700 shrink-0" />
                <div class="flex-1 text-sm sm:text-base">
                    <strong>SYSTEM opened with a one-time link</strong> by {{ $openedBy }}. This sign-in is in <strong>Audit Logs</strong>.
                </div>
            </div>
        </div>
    @elseif($isSystemUser)
        <div class="alert bg-red-100 border-l-4 border-red-500 text-red-900 shadow-md rounded-none" role="alert">
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4 w-full">
                {{-- Left: Warning Icon + Label --}}
                <div class="flex items-center gap-3 shrink-0">
                    <x-icon name="phosphor-warning" class="w-5 h-5 text-red-600" />
                    <span class="font-bold text-sm sm:text-base">SYSTEM ACCOUNT</span>
                    <span class="hidden sm:inline text-red-400">|</span>
                </div>

                {{-- Center: Message --}}
                <div class="flex-1 text-sm sm:text-base">
                    You are signed in as the <strong>SYSTEM account</strong>. Use this account for initial setup. Please create a personal account with your callsign for everyday use.
                </div>

                {{-- Right: Link to User Management --}}
                @can('manage-users')
                    <div class="shrink-0 ml-auto">
                        <a
                            href="{{ route('users.index') }}"
                            class="link link-hover font-semibold text-sm sm:text-base text-red-800"
                            wire:navigate
                        >
                            Manage Users
                        </a>
                    </div>
                @endcan
            </div>
        </div>
    @endif
</div>
