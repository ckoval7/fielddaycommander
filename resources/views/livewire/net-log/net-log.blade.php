{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="space-y-4">
    <x-slot:title>Net Log</x-slot:title>

    <x-header
        title="Net Log"
        subtitle="Log traffic on each net as it happens. Every net control screen sees new entries live."
        separator
        progress-indicator
    >
        <x-slot:actions class="flex-wrap max-sm:w-full">
            @can('view-reports')
                <x-button
                    label="ICS-309"
                    icon="phosphor-printer"
                    class="btn-ghost btn-sm"
                    link="{{ route('reports.ics-309', array_filter(['net' => $netId])) }}"
                    external
                />
            @endcan
        </x-slot:actions>
    </x-header>

    {{-- Net switcher --}}
    <div class="flex flex-wrap items-end gap-2" data-net-switcher>
        @if($this->nets->isNotEmpty())
            <div class="min-w-0 flex-1 sm:flex-initial sm:w-72">
                <x-select
                    label="Net"
                    wire:model.live="netId"
                    :options="$this->nets"
                    option-value="id"
                    option-label="name"
                    icon="phosphor-broadcast"
                />
            </div>
        @endif
        <div class="flex gap-2">
            @if($this->currentNet)
                @can('update', $this->currentNet)
                    <x-button
                        label="Edit net"
                        icon="phosphor-pencil-simple"
                        class="btn-ghost min-h-11"
                        wire:click="openNetForm({{ $this->currentNet->id }})"
                    />
                @endcan
            @endif
            @can('create', \App\Models\Net::class)
                <x-button
                    label="Start net"
                    icon="phosphor-plus"
                    class="btn-primary min-h-11"
                    wire:click="openNetForm"
                />
            @endcan
        </div>
    </div>

    @if($this->currentNet === null)
        <div class="bg-base-100 rounded-lg border border-base-300 p-6 text-center space-y-2">
            <x-icon name="phosphor-broadcast" class="w-10 h-10 mx-auto text-base-content/40" />
            <p class="font-semibold">No nets yet.</p>
            <p class="text-sm text-base-content/70">
                Start a net to begin logging traffic. You can also set up the event's nets ahead of time
                and pick one here when it goes on the air.
            </p>
        </div>
    @else
        <div class="max-sm:-mx-3 space-y-2" x-data="netLogComposer()" wire:key="net-log-{{ $this->currentNet->id }}">
            @php
                $netDetails = collect([
                    $this->currentNet->details(),
                    $this->currentNet->net_control_station ? 'NCS: '.$this->currentNet->net_control_station : null,
                ])->filter()->implode(' · ');
            @endphp
            <x-logging.qso-log
                :title="$this->currentNet->name"
                :subtitle="$netDetails !== '' ? $netDetails : 'Newest at the bottom'"
                example="NCS AID7 WATER LOW"
                empty-title="No net traffic logged yet."
                empty-hint="Type the station being called, then the calling station, then the message, e.g."
                logged-event="net-log-entry-logged"
            >
                <x-slot:headings>
                    <th>Time</th>
                    <th>To</th>
                    <th>From</th>
                    <th>Message</th>
                    <th>By</th>
                </x-slot:headings>

                <x-slot:cards>
                    @foreach($this->entries as $entry)
                        <x-logging.net-entry-card :entry="$entry" />
                    @endforeach
                </x-slot:cards>

                <x-slot:rows>
                    @foreach($this->entries as $entry)
                        <x-logging.net-entry-row :entry="$entry" />
                    @endforeach
                </x-slot:rows>

                <x-logging.exchange-composer
                    placeholder="NCS AID7 WATER LOW"
                    label="Net log entry"
                    logged-event="net-log-entry-logged"
                    :suggestions="$suggestions"
                >
                    @if($parseError)
                        <div class="alert alert-error" data-net-log-error>
                            <x-icon name="phosphor-warning" class="w-5 h-5" />
                            <span>{{ $parseError }}</span>
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-2" x-show="!isRecalling" role="group" aria-label="Priority">
                        <span class="text-xs text-base-content/60">Priority</span>
                        @foreach(\App\Enums\NetPriority::cases() as $option)
                            @php
                                $active = $priority === $option->value;
                                $tone = match ($option) {
                                    \App\Enums\NetPriority::Emergency => 'btn-error',
                                    \App\Enums\NetPriority::Priority => 'btn-warning',
                                    default => 'btn-neutral',
                                };
                            @endphp
                            <button
                                type="button"
                                wire:click="setPriority('{{ $option->value }}')"
                                aria-pressed="{{ $active ? 'true' : 'false' }}"
                                @class(['btn btn-sm touch-manipulation', $tone => $active, 'btn-ghost' => ! $active])
                            >
                                {{ $option->label() }}
                            </button>
                        @endforeach
                        <span class="text-xs text-base-content/50 max-sm:hidden">or start the message with EMERGENCY or PRIORITY</span>
                    </div>
                </x-logging.exchange-composer>
            </x-logging.qso-log>

            <x-logging.keyboard-shortcuts log-label="Log entry" recall-label="Recall entries" />
        </div>
    @endif

    {{-- Start / Edit Net --}}
    <x-modal wire:model="showNetForm" :title="$editingNetId ? 'Edit Net' : 'Start Net'" class="backdrop-blur" separator>
        <form wire:submit="saveNet" class="space-y-4">
            <x-input label="Net name" wire:model="netName" placeholder="Local 2m Net" autocomplete="off" required />

            <x-input
                label="Frequency"
                wire:model="netFrequency"
                placeholder="146.520 MHz"
                autocomplete="off"
            />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select
                    label="Band"
                    wire:model="netBandId"
                    :options="$this->bands"
                    option-value="id"
                    option-label="name"
                    placeholder="None"
                />
                <x-select
                    label="Mode"
                    wire:model="netModeId"
                    :options="$this->modes"
                    option-value="id"
                    option-label="name"
                    placeholder="None"
                />
            </div>

            <x-input
                label="Net control station"
                wire:model="netControlStation"
                placeholder="NCS"
                hint="Callsign or tactical name. It's recognised when you type it in a line."
                autocomplete="off"
                autocapitalize="characters"
            />

            <div class="flex flex-wrap justify-between gap-2 pt-2">
                <div>
                    @if($editingNetId && ($editingNet = $this->nets->firstWhere('id', $editingNetId)))
                        @can('delete', $editingNet)
                            <x-button
                                label="Delete net"
                                icon="phosphor-trash"
                                class="btn-ghost text-error min-h-11"
                                wire:click="deleteNet({{ $editingNetId }})"
                                wire:confirm="Delete this net? It has nothing logged on it."
                                spinner="deleteNet"
                            />
                        @endcan
                    @endif
                </div>
                <div class="flex gap-2">
                    <x-button label="Cancel" class="btn-ghost min-h-11" @click="$wire.showNetForm = false" />
                    <x-button
                        type="submit"
                        :label="$editingNetId ? 'Save' : 'Start net'"
                        icon="phosphor-check"
                        class="btn-primary min-h-11"
                        spinner="saveNet"
                    />
                </div>
            </div>
        </form>
    </x-modal>
</div>
