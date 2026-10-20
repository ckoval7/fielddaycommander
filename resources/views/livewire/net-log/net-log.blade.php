{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="space-y-4">
    <x-slot:title>Net Log</x-slot:title>

    <x-header
        title="Net Log"
        subtitle="Log traffic on the net as it happens. Every net control screen sees new entries live."
        separator
        progress-indicator
    >
        <x-slot:actions class="flex-wrap max-sm:w-full">
            @can('view-reports')
                <x-button
                    label="ICS-309"
                    icon="phosphor-printer"
                    class="btn-ghost btn-sm"
                    link="{{ route('reports.ics-309') }}"
                    external
                />
            @endcan
        </x-slot:actions>
    </x-header>

    <div class="max-sm:-mx-3 space-y-2" x-data="netLogComposer()">
        <x-logging.qso-log
            title="Net Traffic"
            subtitle="Newest at the bottom"
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
</div>
