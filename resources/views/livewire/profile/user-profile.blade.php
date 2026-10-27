{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="container mx-auto px-4 py-6 max-sm:px-0">
    <x-slot:title>Profile Settings</x-slot:title>

    {{-- Page Header --}}
    <div class="mb-6">
        <h1 class="text-3xl font-bold">Profile Settings</h1>
        <p class="text-base-content/70 mt-1">Manage your account settings and preferences</p>
    </div>

    @if(auth()->user()->requires_password_change)
        <x-alert icon="phosphor-warning" class="alert-warning mb-6">
            Change your password to continue using Field Day Commander.
        </x-alert>
    @endif

    {{-- Tab Navigation --}}
    <div role="tablist" aria-label="Profile settings" class="tabs tabs-border mb-6"
         x-on:keydown.arrow-right.prevent="const tabs = [...$el.querySelectorAll('[role=tab]')]; const tab = tabs[(tabs.indexOf(document.activeElement) + 1) % tabs.length]; tab.focus(); tab.click()"
         x-on:keydown.arrow-left.prevent="const tabs = [...$el.querySelectorAll('[role=tab]')]; const tab = tabs[(tabs.indexOf(document.activeElement) + tabs.length - 1) % tabs.length]; tab.focus(); tab.click()"
         x-on:keydown.home.prevent="$el.querySelector('[role=tab]').focus(); $el.querySelector('[role=tab]').click()"
         x-on:keydown.end.prevent="const tab = $el.querySelector('[role=tab]:last-child'); tab.focus(); tab.click()">
        <button type="button" role="tab"
           class="tab {{ $activeTab === 'profile' ? 'tab-active' : '' }}"
           tabindex="{{ $activeTab === 'profile' ? '0' : '-1' }}" aria-selected="{{ $activeTab === 'profile' ? 'true' : 'false' }}"
           aria-controls="profile-tab-panel"
           id="profile-tab-profile"
           wire:click="$set('activeTab', 'profile')">
            <x-icon name="phosphor-user" class="w-4 h-4 mr-2" />
            Profile
        </button>

        <button type="button" role="tab"
           class="tab {{ $activeTab === 'security' ? 'tab-active' : '' }}"
           tabindex="{{ $activeTab === 'security' ? '0' : '-1' }}" aria-selected="{{ $activeTab === 'security' ? 'true' : 'false' }}"
           aria-controls="profile-tab-panel"
           id="profile-tab-security"
           wire:click="$set('activeTab', 'security')">
            <x-icon name="phosphor-shield-check" class="w-4 h-4 mr-2" />
            Security
        </button>

        <button type="button" role="tab"
           class="tab {{ $activeTab === 'sessions' ? 'tab-active' : '' }}"
           tabindex="{{ $activeTab === 'sessions' ? '0' : '-1' }}" aria-selected="{{ $activeTab === 'sessions' ? 'true' : 'false' }}"
           aria-controls="profile-tab-panel"
           id="profile-tab-sessions"
           wire:click="$set('activeTab', 'sessions')">
            <x-icon name="phosphor-desktop" class="w-4 h-4 mr-2" />
            Sign-In Sessions
        </button>

        <button type="button" role="tab"
           class="tab {{ $activeTab === 'operating' ? 'tab-active' : '' }}"
           tabindex="{{ $activeTab === 'operating' ? '0' : '-1' }}" aria-selected="{{ $activeTab === 'operating' ? 'true' : 'false' }}"
           aria-controls="profile-tab-panel"
           id="profile-tab-operating"
           wire:click="$set('activeTab', 'operating')">
            <x-icon name="phosphor-radio" class="w-4 h-4 mr-2" />
            Operating History
        </button>

        <button type="button" role="tab"
           class="tab {{ $activeTab === 'activity' ? 'tab-active' : '' }}"
           tabindex="{{ $activeTab === 'activity' ? '0' : '-1' }}" aria-selected="{{ $activeTab === 'activity' ? 'true' : 'false' }}"
           aria-controls="profile-tab-panel"
           id="profile-tab-activity"
           wire:click="$set('activeTab', 'activity')">
            <x-icon name="phosphor-clock" class="w-4 h-4 mr-2" />
            Activity Log
        </button>
    </div>

    {{-- Tab Content --}}
    <div id="profile-tab-panel" role="tabpanel" aria-labelledby="profile-tab-{{ $activeTab }}" class="profile-tab-content">
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
