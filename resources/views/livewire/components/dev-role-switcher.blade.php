{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
    @if(\App\Support\DeveloperMode::allowsRoleOverride() && auth()->check())
        {{-- Collapsed to one slim row; the controls open on demand. Alpine keeps
             the open state across Livewire re-renders. --}}
        <details
            x-data="{ open: false }"
            :open="open"
            @toggle="open = $el.open"
            class="group bg-purple-50 dark:bg-purple-900/20 border-l-4 border-purple-500 text-purple-900 dark:text-purple-200"
        >
            <summary class="flex items-center gap-2 px-3 sm:px-4 min-h-9 pointer-coarse:min-h-11 cursor-pointer list-none [&::-webkit-details-marker]:hidden text-xs">
                <x-icon name="phosphor-wrench" class="w-4 h-4 shrink-0 text-purple-600 dark:text-purple-400" />
                <span class="font-semibold uppercase tracking-wide shrink-0">Role Switcher</span>
                <span class="truncate min-w-0 opacity-80">
                    @if($isActive)
                        {{ $role !== '' ? $role : 'Own role' }}@if($callSign !== '') · {{ $callSign }}@endif
                    @else
                        Your own role
                    @endif
                </span>
                @if($isActive)
                    <span class="badge badge-xs badge-primary shrink-0">active</span>
                @endif
                <span class="ml-auto shrink-0 flex items-center gap-1 font-medium">
                    <span class="group-open:hidden">Change</span>
                    <span class="hidden group-open:inline">Close</span>
                    <x-icon name="phosphor-caret-down" class="w-3.5 h-3.5 transition-transform group-open:rotate-180" />
                </span>
            </summary>

            <div class="flex flex-wrap items-end gap-x-4 gap-y-2 px-3 sm:px-4 pb-3 pt-1">
                <div class="flex flex-col gap-1">
                    <label for="dev-role" class="text-xs font-medium">Role</label>
                    <select
                        id="dev-role"
                        wire:model.live="role"
                        class="select select-sm bg-base-100 text-base-content min-w-[160px]"
                    >
                        <option value="">Use My Role</option>
                        @foreach($roles as $availableRole)
                            <option value="{{ $availableRole }}">{{ $availableRole }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="dev-callsign" class="text-xs font-medium">Callsign</label>
                    <input
                        id="dev-callsign"
                        type="text"
                        wire:model.live.debounce.500ms="callSign"
                        autocomplete="off"
                        autocapitalize="characters"
                        autocorrect="off"
                        spellcheck="false"
                        class="input input-sm bg-base-100 text-base-content w-36 uppercase"
                        placeholder="e.g. W1AW"
                        aria-describedby="dev-callsign-example"
                    />
                    <span id="dev-callsign-example" hidden>{{ spokenPlaceholder('e.g. W1AW') }}</span>
                </div>

                @if($isActive)
                    <button
                        wire:click="resetOverrides"
                        type="button"
                        class="btn btn-sm btn-outline border-purple-400 text-purple-700 dark:text-purple-300 hover:bg-purple-200 dark:hover:bg-purple-800 hover:border-purple-500"
                    >
                        Clear Overrides
                    </button>
                @endif
            </div>
        </details>
    @endif
</div>
