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
                        wire:click="$dispatchTo('net-log.net-form', 'open-net-form', { netId: {{ $this->currentNet->id }} })"
                    />
                @endcan
            @endif
            @can('create', \App\Models\Net::class)
                <x-button
                    label="Start net"
                    icon="phosphor-plus"
                    class="btn-primary min-h-11"
                    wire:click="$dispatchTo('net-log.net-form', 'open-net-form')"
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
                    :suggestions="$suggestions"
                >
                    @if($parseError)
                        <div class="alert alert-error" data-net-log-error>
                            <x-icon name="phosphor-warning" class="w-5 h-5" />
                            <span>{{ $parseError }}</span>
                        </div>
                    @endif

                    <fieldset class="flex flex-wrap items-center gap-2" x-show="!isRecalling">
                        <legend class="sr-only">Priority</legend>
                        <span class="text-xs text-base-content/60" aria-hidden="true">Priority</span>
                        @foreach(\App\Enums\NetPriority::cases() as $option)
                            @php
                                $active = $priority === $option->value;
                                $tone = 'btn-'.($option->tone() ?? 'neutral');
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
                    </fieldset>
                </x-logging.exchange-composer>
            </x-logging.qso-log>

            <x-logging.keyboard-shortcuts log-label="Log entry" recall-label="Recall entries" />
        </div>
    @endif

    <livewire:net-log.net-form :event="$event" />
</div>
