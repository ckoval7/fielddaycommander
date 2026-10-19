{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@php
    $priorityBadge = fn (\App\Enums\NetPriority $priority) => match ($priority) {
        \App\Enums\NetPriority::Emergency => ['class' => 'badge-error', 'icon' => 'phosphor-siren'],
        \App\Enums\NetPriority::Priority => ['class' => 'badge-warning', 'icon' => 'phosphor-warning'],
        default => ['class' => 'badge-neutral', 'icon' => 'phosphor-chat-text'],
    };
@endphp
<div class="space-y-6">
    <x-slot:title>Net Log</x-slot:title>

    <x-header
        title="Net Log"
        subtitle="Log traffic on the net as it happens. Every net control screen sees new entries live."
        separator
        progress-indicator
    />

    {{-- Quick entry --}}
    <x-card shadow>
        <x-slot:title>New Entry</x-slot:title>

        <form wire:submit="logEntry" class="space-y-4" x-on:net-log-entry-saved.window="$refs.fromStation?.focus()">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input
                    label="From"
                    wire:model="fromStation"
                    x-ref="fromStation"
                    icon="phosphor-broadcast"
                    placeholder="Aid 7"
                    hint="Tactical name or callsign of the calling station"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    autocomplete="off"
                    maxlength="50"
                    required
                />
                <x-input
                    label="To"
                    wire:model="toStation"
                    icon="phosphor-radio"
                    placeholder="Net Control"
                    hint="Leave blank for a call to the whole net"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    autocomplete="off"
                    maxlength="50"
                />
            </div>

            <x-textarea
                label="Message"
                wire:model="message"
                placeholder="Runner down at mile 14, requesting medical"
                rows="3"
                maxlength="2000"
                required
            />

            <div class="flex flex-col sm:flex-row sm:items-end gap-4">
                <div class="sm:w-56">
                    <x-select
                        label="Priority"
                        wire:model="priority"
                        :options="$this->priorityOptions"
                        option-value="id"
                        option-label="name"
                        icon="phosphor-flag"
                    />
                </div>
                <x-button
                    type="submit"
                    label="Log Entry"
                    icon="phosphor-check"
                    class="btn-primary max-sm:w-full sm:ml-auto"
                    spinner="logEntry"
                    wire:loading.attr="disabled"
                />
            </div>
        </form>
    </x-card>

    {{-- Entries --}}
    <x-card shadow>
        @if($this->entries->isEmpty())
            <div class="text-center py-10 text-base-content/60">
                <div class="flex flex-col items-center gap-2">
                    <x-icon name="phosphor-list-checks" class="w-12 h-12 opacity-30" />
                    <p class="font-medium">No net traffic logged yet</p>
                    <p class="text-sm">Entries you log above appear here, newest first.</p>
                </div>
            </div>
        @else
            {{-- Desktop Table View --}}
            <div class="hidden lg:block overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Message</th>
                            <th>Priority</th>
                            <th>Logged By</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->entries as $entry)
                            @php $badge = $priorityBadge($entry->priority); @endphp
                            <tr wire:key="net-entry-{{ $entry->id }}">
                                <td class="whitespace-nowrap">
                                    <span class="font-mono text-sm">{{ toLocalTime($entry->logged_at)->format(timeFormat().' T') }}</span>
                                </td>
                                <td class="font-medium">{{ \App\Models\NetLogEntry::displayStation($entry->from_station, $entry->from_callsign) }}</td>
                                <td>{{ \App\Models\NetLogEntry::displayStation($entry->to_station, $entry->to_callsign) ?: 'All stations' }}</td>
                                <td class="whitespace-pre-line break-words max-w-md">{{ $entry->message }}</td>
                                <td>
                                    <span class="badge {{ $badge['class'] }} badge-sm gap-1">
                                        <x-icon :name="$badge['icon']" class="w-3 h-3" />{{ $entry->priority->label() }}
                                    </span>
                                </td>
                                <td class="text-sm text-base-content/60">{{ $entry->user?->call_sign ?? '—' }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('update', $entry)
                                            <x-button
                                                icon="phosphor-pencil-simple"
                                                class="btn-ghost btn-xs"
                                                tooltip="Edit"
                                                aria-label="Edit entry"
                                                wire:click="editEntry({{ $entry->id }})"
                                            />
                                        @endcan
                                        @can('delete', $entry)
                                            <x-button
                                                icon="phosphor-trash"
                                                class="btn-ghost btn-xs text-error"
                                                tooltip="Delete"
                                                aria-label="Delete entry"
                                                wire:click="deleteEntry({{ $entry->id }})"
                                                wire:confirm="Delete this net log entry?"
                                            />
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile Card View --}}
            <div class="lg:hidden grid grid-cols-1 gap-3">
                @foreach($this->entries as $entry)
                    @php $badge = $priorityBadge($entry->priority); @endphp
                    <x-card wire:key="net-entry-mobile-{{ $entry->id }}" class="shadow-sm">
                        <div class="flex flex-col gap-2">
                            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                <span class="font-mono text-sm">{{ toLocalTime($entry->logged_at)->format(timeFormat().' T') }}</span>
                                <span class="badge {{ $badge['class'] }} badge-sm gap-1">
                                    <x-icon :name="$badge['icon']" class="w-3 h-3" />{{ $entry->priority->label() }}
                                </span>
                            </div>
                            <div class="font-medium break-words">
                                {{ \App\Models\NetLogEntry::displayStation($entry->from_station, $entry->from_callsign) }}
                                <x-icon name="phosphor-arrow-right" class="w-3.5 h-3.5 inline opacity-60" />
                                {{ \App\Models\NetLogEntry::displayStation($entry->to_station, $entry->to_callsign) ?: 'All stations' }}
                            </div>
                            <p class="whitespace-pre-line break-words">{{ $entry->message }}</p>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm text-base-content/60">{{ $entry->user?->call_sign }}</span>
                                <div class="flex items-center gap-2">
                                    @can('update', $entry)
                                        <x-button
                                            icon="phosphor-pencil-simple"
                                            class="btn-ghost btn-sm btn-square"
                                            aria-label="Edit entry"
                                            wire:click="editEntry({{ $entry->id }})"
                                        />
                                    @endcan
                                    @can('delete', $entry)
                                        <x-button
                                            icon="phosphor-trash"
                                            class="btn-ghost btn-sm btn-square text-error"
                                            aria-label="Delete entry"
                                            wire:click="deleteEntry({{ $entry->id }})"
                                            wire:confirm="Delete this net log entry?"
                                        />
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </x-card>
                @endforeach
            </div>

            @if($this->entries->hasPages())
                <div class="mt-4">
                    {{ $this->entries->links() }}
                </div>
            @endif
        @endif
    </x-card>

    {{-- Edit modal --}}
    <x-modal wire:model="showEditModal" title="Edit Net Log Entry" class="backdrop-blur">
        <div class="space-y-4">
            <x-flatpickr
                label="Time (UTC)"
                wire:model="editLoggedAt"
                icon="phosphor-clock"
                now-button
            />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input
                    label="From"
                    wire:model="editFromStation"
                    icon="phosphor-broadcast"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    autocomplete="off"
                    maxlength="50"
                    required
                />
                <x-input
                    label="To"
                    wire:model="editToStation"
                    icon="phosphor-radio"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    autocomplete="off"
                    maxlength="50"
                />
            </div>
            <x-textarea label="Message" wire:model="editMessage" rows="3" maxlength="2000" required />
            <x-select
                label="Priority"
                wire:model="editPriority"
                :options="$this->priorityOptions"
                option-value="id"
                option-label="name"
                icon="phosphor-flag"
            />
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="closeEditModal" />
            <x-button label="Save" wire:click="updateEntry" class="btn-primary" spinner="updateEntry" wire:loading.attr="disabled" />
        </x-slot:actions>
    </x-modal>
</div>
