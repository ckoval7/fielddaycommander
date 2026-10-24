{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
ListWidget View

Displays scrollable lists of data in three formats; items new since the
previous render get the dashboard-item-new highlight (see HighlightsNewItems):
- recent_contacts: Recent QSOs with time, callsign, band, mode
- active_stations: Active stations with operator, band, status
- equipment_status: Equipment with status and assignment
- net_traffic: Newest net log entries with from, to, net, message and priority
- station_status: Net positions with location, operator, last heard and overdue state

Size variants:
- normal: 15 items, standard text, max-h-96
- tv: 10 items, larger text, clipped to the tile height (no scrolling)
--}}

@php
    $titles = [
        'recent_contacts' => 'Recent Contacts',
        'active_stations' => 'Active Stations',
        'equipment_status' => 'Equipment Status',
        'net_traffic' => 'Net Traffic',
        'station_status' => 'Station Status',
    ];
    $title = $titles[$listType] ?? 'List';
    $titleSize = $size === 'tv' ? 'text-2xl' : 'text-lg';
@endphp

<div class="h-full" wire:poll.visible.15s>
<x-card body-class="flex flex-col min-h-0" class="h-full flex flex-col" shadow>
    {{-- Card Title --}}
    <div class="@if($size === 'tv') mb-4 @else mb-3 @endif">
        <h3 class="{{ $titleSize }} font-bold text-base-content">{{ $title }}</h3>
    </div>

    <div class="flex-1 min-h-0">
        @if(count($data['items'] ?? []) > 0)
        {{-- List container: scrolls on interactive dashboards; on a TV nobody
             can scroll, so the newest items show and the rest are clipped. --}}
        <div class="@if($size === 'tv') h-full overflow-hidden @else overflow-y-auto @endif">
            <div class="@if($size === 'tv') space-y-4 @else space-y-3 @endif">
                @foreach($data['items'] ?? [] as $item)

                    @if($item['type'] === 'recent_contact')
                        {{-- Recent Contact Item --}}
                        <div
                            wire:key="list-item-{{ $item['key'] }}"
                            class="@if($size === 'tv') p-4 @else p-3 @endif bg-base-100 border border-base-content/10 rounded-lg transition-all duration-200 {{ in_array($item['key'], $newItemKeys, true) ? 'dashboard-item-new' : '' }}"
                        >
                            <div class="flex flex-col gap-2">
                                {{-- Time and Callsign Row --}}
                                <div class="flex items-baseline justify-between gap-3">
                                    <div class="@if($size === 'tv') text-2xl @else text-lg @endif font-bold text-primary transition-all duration-200">
                                        {{ $item['callsign'] }}
                                    </div>
                                    <div class="@if($size === 'tv') text-base @else text-xs @endif text-base-content/70 flex-shrink-0">
                                        {{ $item['time_ago'] }}
                                    </div>
                                </div>
                                {{-- Band, Mode, Operator Row --}}
                                <div class="flex items-center gap-3 @if($size === 'tv') text-lg @else text-sm @endif text-base-content/80">
                                    <span class="font-medium">{{ $item['band'] }}</span>
                                    <span class="text-base-content/50">•</span>
                                    <span class="font-medium">{{ $item['mode'] }}</span>
                                    <span class="text-base-content/50">•</span>
                                    <span>{{ $item['operator'] }}</span>
                                </div>
                            </div>
                        </div>

                    @elseif($item['type'] === 'active_station')
                        {{-- Active Station Item --}}
                        <div
                            wire:key="list-item-{{ $item['key'] }}"
                            class="@if($size === 'tv') p-4 @else p-3 @endif bg-base-100 border border-base-content/10 rounded-lg transition-all duration-200 {{ in_array($item['key'], $newItemKeys, true) ? 'dashboard-item-new' : '' }}"
                        >
                            <div class="flex flex-col gap-2">
                                {{-- Station Name --}}
                                <div class="@if($size === 'tv') text-2xl @else text-lg @endif font-bold transition-all duration-200">
                                    {{ $item['station_name'] }}
                                </div>
                                {{-- Operator and Band Row --}}
                                <div class="flex items-center gap-2 @if($size === 'tv') text-lg @else text-sm @endif text-base-content/80">
                                    <span>{{ $item['operator_name'] }}</span>
                                    <span class="text-base-content/50">operating on</span>
                                    <span class="font-medium">{{ $item['band'] }}</span>
                                    <span class="font-medium">{{ $item['mode'] }}</span>
                                </div>
                                {{-- Status Badge --}}
                                <div>
                                    <x-badge
                                        :value="$item['status']"
                                        class="badge-{{ $item['status_color'] }} {{ $size === 'tv' ? 'badge-md' : 'badge-sm' }}"
                                    />
                                </div>
                            </div>
                        </div>

                    @elseif($item['type'] === 'net_entry')
                        {{-- Net Log Entry Item --}}
                        <div
                            wire:key="list-item-{{ $item['key'] }}"
                            class="@if($size === 'tv') p-4 @else p-3 @endif bg-base-100 border border-base-content/10 rounded-lg transition-all duration-200 {{ in_array($item['key'], $newItemKeys, true) ? 'dashboard-item-new' : '' }}"
                        >
                            <div class="flex flex-col gap-1.5">
                                <div class="flex items-baseline justify-between gap-3">
                                    <div class="@if($size === 'tv') text-2xl @else text-lg @endif font-bold font-mono break-words min-w-0">
                                        {{ $item['from'] }} <span class="font-normal text-base-content/50">&rarr;</span> {{ $item['to'] }}
                                    </div>
                                    <div class="@if($size === 'tv') text-base @else text-xs @endif text-base-content/70 flex-shrink-0">
                                        {{ $item['time_ago'] }}
                                    </div>
                                </div>
                                <div class="@if($size === 'tv') text-lg @else text-sm @endif text-base-content/80 break-words">
                                    @if($item['net'])
                                        <span class="badge badge-ghost {{ $size === 'tv' ? 'badge-md' : 'badge-sm' }} mr-1 align-middle">{{ $item['net'] }}</span>
                                    @endif
                                    <x-logging.net-priority-badge :priority="\App\Enums\NetPriority::from($item['priority'])" :size="$size === 'tv' ? 'badge-md' : 'badge-sm'" />
                                    <span class="whitespace-pre-line">{{ $item['message'] }}</span>
                                </div>
                            </div>
                        </div>

                    @elseif($item['type'] === 'station_status')
                        {{-- Station Status Item --}}
                        <div
                            wire:key="list-item-{{ $item['key'] }}"
                            @class([
                                'bg-base-100 border rounded-lg transition-all duration-200',
                                $size === 'tv' ? 'p-4' : 'p-3',
                                'border-error bg-error/10' => $item['overdue'],
                                'border-base-content/10' => ! $item['overdue'],
                            ])
                            data-station-status-item
                        >
                            <div class="flex flex-col gap-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="@if($size === 'tv') text-2xl @else text-lg @endif font-bold break-words min-w-0">
                                        {{ $item['name'] }}
                                    </div>
                                    @if($item['unavailable'])
                                        <x-badge value="Out of service" class="badge-ghost {{ $size === 'tv' ? 'badge-md' : 'badge-sm' }} shrink-0" />
                                    @elseif($item['overdue'])
                                        <span class="badge badge-error gap-1 {{ $size === 'tv' ? 'badge-md' : 'badge-sm' }} shrink-0">
                                            <x-icon name="phosphor-warning" class="w-3 h-3" />
                                            Overdue
                                        </span>
                                    @endif
                                </div>
                                @if($item['location'])
                                    <div class="flex items-start gap-1 @if($size === 'tv') text-lg @else text-sm @endif text-base-content/70 break-words">
                                        <x-icon name="phosphor-map-pin" class="w-4 h-4 shrink-0 mt-0.5" />
                                        <span class="min-w-0">{{ $item['location'] }}</span>
                                    </div>
                                @endif
                                <div class="flex flex-wrap justify-between gap-x-3 @if($size === 'tv') text-lg @else text-sm @endif text-base-content/80">
                                    <span>{{ $item['operator'] ?? 'No operator on duty' }}</span>
                                    <span><span class="sr-only">Last heard</span> {{ $item['last_heard'] ?? 'Not heard yet' }}</span>
                                </div>
                            </div>
                        </div>

                    @elseif($item['type'] === 'equipment')
                        {{-- Equipment Status Item --}}
                        <div
                            wire:key="list-item-{{ $item['key'] }}"
                            class="@if($size === 'tv') p-4 @else p-3 @endif bg-base-100 border border-base-content/10 rounded-lg transition-all duration-200 {{ in_array($item['key'], $newItemKeys, true) ? 'dashboard-item-new' : '' }}"
                        >
                            <div class="flex flex-col gap-2">
                                {{-- Equipment Name --}}
                                <div class="@if($size === 'tv') text-2xl @else text-lg @endif font-bold transition-all duration-200">
                                    {{ $item['equipment_name'] }}
                                </div>
                                {{-- Status and Assignment Row --}}
                                <div class="flex items-center gap-3 flex-wrap">
                                    <x-badge
                                        :value="$item['status']"
                                        class="badge-{{ $item['status_color'] }} {{ $size === 'tv' ? 'badge-md' : 'badge-sm' }}"
                                    />
                                    @if($item['assigned_to'] !== 'Unassigned')
                                        <span class="@if($size === 'tv') text-lg @else text-sm @endif text-base-content/70">
                                            Assigned to {{ $item['assigned_to'] }}
                                        </span>
                                    @else
                                        <span class="@if($size === 'tv') text-lg @else text-sm @endif text-base-content/50 italic">
                                            Not assigned
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
        @else
            {{-- Empty State - Contextual Messages --}}
            <div class="flex items-center justify-center @if($size === 'tv') min-h-[400px] @else min-h-[200px] @endif">
                <div class="text-center px-4">
                    @php
                        $emptyMessages = [
                            'recent_contacts' => [
                                'icon' => 'phosphor-radio',
                                'title' => 'No contacts logged yet',
                                'message' => 'Logged contacts appear here.',
                            ],
                            'active_stations' => [
                                'icon' => 'phosphor-cell-signal-high',
                                'title' => 'No active stations',
                                'message' => 'Ready to get on the air? Hop on a station, start an operating session, and make some contacts!',
                            ],
                            'equipment_status' => [
                                'icon' => 'phosphor-wrench',
                                'title' => 'No equipment to show',
                                'message' => 'Committed and delivered equipment appears here.',
                            ],
                            'net_traffic' => [
                                'icon' => 'phosphor-list-checks',
                                'title' => 'No net traffic yet',
                                'message' => 'Entries logged in the Net Log will appear here',
                            ],
                            'station_status' => [
                                'icon' => 'phosphor-map-pin',
                                'title' => 'No stations to show',
                                'message' => 'Stations with a tactical callsign or location appear here',
                            ],
                        ];
                        $empty = $emptyMessages[$listType] ?? ['icon' => 'phosphor-tray', 'title' => 'No data available', 'message' => ''];
                        $iconSize = $size === 'tv' ? 'w-16 h-16' : 'w-12 h-12';
                        $titleSize = $size === 'tv' ? 'text-2xl' : 'text-base';
                        $messageSize = $size === 'tv' ? 'text-lg' : 'text-sm';
                    @endphp

                    <x-icon
                        :name="$empty['icon']"
                        class="{{ $iconSize }} text-base-content/30 mx-auto mb-3"
                    />
                    <div class="{{ $titleSize }} font-semibold text-base-content/70 mb-1">
                        {{ $empty['title'] }}
                    </div>
                    @if($empty['message'])
                        <div class="{{ $messageSize }} text-base-content/50">
                            {{ $empty['message'] }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Last updated timestamp --}}
    <x-dashboard.updated-at :at="$data['last_updated_at'] ?? null" class="border-t border-base-content/5" />
</x-card>

</div>
