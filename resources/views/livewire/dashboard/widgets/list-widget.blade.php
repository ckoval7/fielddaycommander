{{--
ListWidget View

Displays scrollable lists of data in three formats with slide-down animations for new items:
- recent_contacts: Recent QSOs with time, callsign, band, mode
- active_stations: Active stations with operator, band, status
- equipment_status: Equipment with status and assignment

Size variants:
- normal: 15 items, standard text, max-h-96
- tv: 10 items, larger text, max-h-[600px]
--}}

@php
    $titles = [
        'recent_contacts' => 'Recent Contacts',
        'active_stations' => 'Active Stations',
        'equipment_status' => 'Equipment Status',
    ];
    $title = $titles[$listType] ?? 'List';
    $titleSize = $size === 'tv' ? 'text-2xl' : 'text-lg';
@endphp

<div class="h-full">
<x-card class="h-full flex flex-col" shadow>
    {{-- Card Title --}}
    <div class="@if($size === 'tv') mb-4 @else mb-3 @endif">
        <h3 class="{{ $titleSize }} font-bold text-base-content">{{ $title }}</h3>
    </div>

    <div
        class="flex-1 min-h-0"
        x-data="{
            itemIds: @js(array_map(fn($item) => $item['type'] . '-' . ($item['callsign'] ?? $item['station_name'] ?? $item['equipment_name'] ?? 'unknown'), $data['items'] ?? [])),
            newItems: new Set(),

            init() {
                // Track initial items as seen
                this.itemIds.forEach(id => this.newItems.add(id));

                // Remove highlight after delay
                setTimeout(() => {
                    this.newItems.clear();
                }, @js($size === 'tv' ? 1500 : 1000));
            },

            checkNewItems(currentIds) {
                const previous = new Set(this.itemIds);
                const current = new Set(currentIds);

                // Find items that are in current but not in previous
                current.forEach(id => {
                    if (!previous.has(id)) {
                        this.newItems.add(id);

                        // Remove highlight after delay
                        setTimeout(() => {
                            this.newItems.delete(id);
                        }, @js($size === 'tv' ? 1500 : 1000));
                    }
                });

                this.itemIds = currentIds;
            }
        }"
        x-effect="checkNewItems(@js(array_map(fn($item) => $item['type'] . '-' . ($item['callsign'] ?? $item['station_name'] ?? $item['equipment_name'] ?? 'unknown'), $data['items'] ?? [])))"
    >
        @if(count($data['items'] ?? []) > 0)
        {{-- Scrollable List Container --}}
        <div class="overflow-y-auto @if($size === 'tv') max-h-[600px] @endif">
            <div class="@if($size === 'tv') space-y-4 @else space-y-3 @endif">
                @foreach($data['items'] ?? [] as $item)
                    @php
                        $itemKey = $item['type'] . '-' . ($item['callsign'] ?? $item['station_name'] ?? $item['equipment_name'] ?? 'unknown');
                    @endphp

                    @if($item['type'] === 'recent_contact')
                        {{-- Recent Contact Item --}}
                        <div
                            x-data="{ itemId: '{{ $itemKey }}' }"
                            class="@if($size === 'tv') p-4 @else p-3 @endif bg-base-100 border border-base-content/10 rounded-lg transition-all duration-200"
                            ::class="{
                                'animate-slide-down': newItems.has(itemId),
                                'bg-primary/20 border-primary/50 shadow-md shadow-primary/20': newItems.has(itemId)
                            }"
                        >
                            <div class="flex flex-col gap-2">
                                {{-- Time and Callsign Row --}}
                                <div class="flex items-baseline justify-between gap-3">
                                    <div class="@if($size === 'tv') text-2xl @else text-lg @endif font-bold text-primary transition-all duration-200"
                                         ::class="{ 'scale-105': newItems.has(itemId) }">
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
                            x-data="{ itemId: '{{ $itemKey }}' }"
                            class="@if($size === 'tv') p-4 @else p-3 @endif bg-base-100 border border-base-content/10 rounded-lg transition-all duration-200"
                            ::class="{
                                'animate-slide-down': newItems.has(itemId),
                                'bg-success/20 border-success/50 shadow-md shadow-success/20': newItems.has(itemId)
                            }"
                        >
                            <div class="flex flex-col gap-2">
                                {{-- Station Name --}}
                                <div class="@if($size === 'tv') text-2xl @else text-lg @endif font-bold transition-all duration-200"
                                     ::class="{ 'scale-105': newItems.has(itemId) }">
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
                                        class="badge-{{ $item['status_color'] }} @if($size === 'tv') badge-md @else badge-sm @endif"
                                    />
                                </div>
                            </div>
                        </div>

                    @elseif($item['type'] === 'equipment')
                        {{-- Equipment Status Item --}}
                        <div
                            x-data="{ itemId: '{{ $itemKey }}' }"
                            class="@if($size === 'tv') p-4 @else p-3 @endif bg-base-100 border border-base-content/10 rounded-lg transition-all duration-200"
                            ::class="{
                                'animate-slide-down': newItems.has(itemId),
                                'bg-info/20 border-info/50 shadow-md shadow-info/20': newItems.has(itemId)
                            }"
                        >
                            <div class="flex flex-col gap-2">
                                {{-- Equipment Name --}}
                                <div class="@if($size === 'tv') text-2xl @else text-lg @endif font-bold transition-all duration-200"
                                     ::class="{ 'scale-105': newItems.has(itemId) }">
                                    {{ $item['equipment_name'] }}
                                </div>
                                {{-- Status and Assignment Row --}}
                                <div class="flex items-center gap-3 flex-wrap">
                                    <x-badge
                                        :value="$item['status']"
                                        class="badge-{{ $item['status_color'] }} @if($size === 'tv') badge-md @else badge-sm @endif"
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
                                'message' => 'Start making contacts to see them appear here',
                            ],
                            'active_stations' => [
                                'icon' => 'phosphor-cell-signal-high',
                                'title' => 'No active stations',
                                'message' => 'Ready to get on the air? Hop on a station and start making contacts!',
                            ],
                            'equipment_status' => [
                                'icon' => 'phosphor-wrench',
                                'title' => 'No equipment status',
                                'message' => 'Equipment assignments will appear here',
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
    <div class="text-xs text-base-content/40 text-right mt-auto pt-2 border-t border-base-content/5">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
</x-card>

    <style>
        @keyframes slide-down {
            from {
                opacity: 0;
                transform: translateY(-20px);
                max-height: 0;
            }
            to {
                opacity: 1;
                transform: translateY(0);
                max-height: 500px;
            }
        }

        .animate-slide-down {
            animation: slide-down 0.4s ease-out;
        }
    </style>
</div>
