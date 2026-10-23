{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="container mx-auto px-4 py-6 max-sm:px-0">
    {{-- Page Header --}}
    <div class="mb-6">
        <h1 class="text-3xl font-bold">Profile Settings</h1>
        <p class="text-gray-600 mt-1">Manage your account settings and preferences</p>
    </div>

    @if(auth()->user()->requires_password_change)
        <x-alert icon="phosphor-warning" class="alert-warning mb-6">
            Change your password to continue using Field Day Commander.
        </x-alert>
    @endif

    {{-- Tab Navigation --}}
    <div role="tablist" class="tabs tabs-border mb-6">
        <a role="tab"
           class="tab {{ $activeTab === 'profile' ? 'tab-active' : '' }}"
           wire:click="$set('activeTab', 'profile')">
            <x-icon name="phosphor-user" class="w-4 h-4 mr-2" />
            Profile
        </a>

        <a role="tab"
           class="tab {{ $activeTab === 'security' ? 'tab-active' : '' }}"
           wire:click="$set('activeTab', 'security')">
            <x-icon name="phosphor-shield-check" class="w-4 h-4 mr-2" />
            Security
        </a>

        <a role="tab"
           class="tab {{ $activeTab === 'sessions' ? 'tab-active' : '' }}"
           wire:click="$set('activeTab', 'sessions')">
            <x-icon name="phosphor-desktop" class="w-4 h-4 mr-2" />
            Sign-In Sessions
        </a>

        <a role="tab"
           class="tab {{ $activeTab === 'operating' ? 'tab-active' : '' }}"
           wire:click="$set('activeTab', 'operating')">
            <x-icon name="phosphor-radio" class="w-4 h-4 mr-2" />
            Operating History
        </a>

        <a role="tab"
           class="tab {{ $activeTab === 'activity' ? 'tab-active' : '' }}"
           wire:click="$set('activeTab', 'activity')">
            <x-icon name="phosphor-clock" class="w-4 h-4 mr-2" />
            Activity Log
        </a>
    </div>

    {{-- Tab Content --}}
    <div class="profile-tab-content">
        @if($activeTab === 'profile')
            @include('livewire.profile.partials.profile-tab')
        @elseif($activeTab === 'security')
            @include('livewire.profile.partials.security-tab')
        @elseif($activeTab === 'sessions')
            @include('livewire.profile.partials.sessions-tab')
        @elseif($activeTab === 'operating')
            @include('livewire.profile.partials.operating-tab')
        @elseif($activeTab === 'activity')
            @include('livewire.profile.partials.activity-tab')
        @endif
    </div>
</div>
