{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@auth
@if(\App\Support\StationMode::isActive())
<div class="flex items-center gap-2" data-station-mode-controls>
    <x-theme-switch compact />
    <form method="POST" action="{{ route('station-mode.end') }}" data-navigate-ignore x-data @submit="globalThis.stampQueuedSessions?.($el)">
        @csrf
        <button type="submit" class="btn btn-error btn-sm sm:btn-md" title="Sign out of station mode">
            <x-icon name="phosphor-sign-out" class="w-4 h-4" />
            <span>Sign Out</span>
            <span class="font-mono hidden sm:inline">({{ auth()->user()->call_sign }})</span>
        </button>
    </form>
</div>
@else
<div class="dropdown dropdown-end">
    <button tabindex="0" class="btn btn-ghost btn-circle btn-md avatar" aria-label="Account menu for {{ auth()->user()->effectiveCallSign() }}" aria-haspopup="menu">
        <div class="w-10 rounded-full">
            @if(auth()->user()->avatar_path && file_exists(public_path(auth()->user()->avatar_path)))
                <img src="{{ asset(auth()->user()->avatar_path) }}" alt="" class="rounded-full">
            @else
                <div class="avatar avatar-placeholder">
                    <div class="bg-slate-600 text-white rounded-full w-10 flex items-center justify-center">
                        <span class="text-sm" aria-hidden="true">{{ auth()->user()->getInitials() }}</span>
                    </div>
                </div>
            @endif
        </div>
    </button>

    <ul class="menu menu-sm dropdown-content mt-3 z-[100] p-2 shadow-lg bg-base-100 rounded-box w-64 border border-base-300">
        <!-- User info header -->
        <li class="menu-title px-3 py-2">
            <div class="flex flex-col gap-1">
                <span class="text-base font-bold">{{ auth()->user()->effectiveCallSign() }}</span>
                @if(auth()->user()->name)
                    <span class="text-xs opacity-60">{{ auth()->user()->name }}</span>
                @endif
                @if(auth()->user()->license_class)
                    <span class="text-xs opacity-70">{{ auth()->user()->license_class }}</span>
                @endif
            </div>
        </li>

        <div class="divider my-1"></div>

        <!-- Theme toggle -->
        <x-theme-switch as="li" />

        <!-- Menu items -->
        <li>
            <a href="{{ route('profile') }}" wire:navigate>
                <x-icon name="phosphor-user" class="w-4 h-4" />
                Profile
            </a>
        </li>
        <li>
            <a href="{{ route('schedule.my-shifts') }}" wire:navigate>
                <x-icon name="phosphor-calendar-dots" class="w-4 h-4" />
                My Shifts
            </a>
        </li>

        @if(\App\Support\StationMode::deviceFromCookie(request()) && auth()->user()->can('manage-stations'))
            <li>
                <a href="{{ route('station-mode.landing') }}">
                    <x-icon name="phosphor-desktop-tower" class="w-4 h-4" />
                    Station Mode Browser
                </a>
            </li>
        @endif

        @can('manage-users')
            <div class="divider my-1"></div>

            <li>
                <a href="{{ route('users.index') }}" wire:navigate>
                    <x-icon name="phosphor-users-three" class="w-4 h-4" />
                    Manage Users
                </a>
            </li>
        @endcan

        @can('access-settings')
            <li>
                <a href="{{ route('settings.index') }}" wire:navigate>
                    <x-icon name="phosphor-gear-six" class="w-4 h-4" />
                    System Settings
                </a>
            </li>
        @endcan

        <div class="divider my-1"></div>

        <li>
            <form method="POST" action="{{ route('logout') }}" data-navigate-ignore>
                @csrf
                <button type="submit" class="text-error">
                    <x-icon name="phosphor-sign-out" class="w-4 h-4" />
                    Sign Out
                </button>
            </form>
        </li>
    </ul>
</div>
@endif
@else
<a href="{{ route('login') }}" wire:navigate class="btn btn-primary">
    Sign In
</a>
@endauth
