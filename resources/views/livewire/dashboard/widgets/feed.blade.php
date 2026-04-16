{{--
Feed Widget View

Displays a scrollable list of recent activity notifications with animations.
New items fade in from top with 2-second highlight.

Props from component:
- $data: Array with 'items' (feed items), 'feed_type', 'feed_label'
- $size: 'normal' or 'tv'

Each item: id, icon, title, message, time_ago, read
--}}

<div class="h-full">
<x-card class="h-full flex flex-col" shadow>
<div
    class="flex-1 flex flex-col"
    x-data="{
        itemIds: @js(array_column($data['items'], 'id')),
        newItems: new Set(),

        init() {
            // Track initial items as seen
            this.itemIds.forEach(id => this.newItems.add(id));

            // Remove highlight after 2 seconds
            setTimeout(() => {
                this.newItems.clear();
            }, @js($size === 'tv' ? 3000 : 2000));
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
                    }, @js($size === 'tv' ? 3000 : 2000));
                }
            });

            this.itemIds = currentIds;
        }
    }"
    x-effect="checkNewItems(@js(array_column($data['items'], 'id')))"
>
    @if ($size === 'tv')
        {{-- TV Mode: Larger text and spacing for kiosk/TV dashboards --}}
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-2xl font-bold text-base-content">
                {{ $data['feed_label'] }}
            </h2>
            <x-badge value="{{ count($data['items']) }} items" class="badge-ghost badge-lg" />
        </div>

        <div class="overflow-y-auto max-h-[500px] space-y-3 pr-1">
            @forelse ($data['items'] as $item)
                <div
                    wire:key="feed-item-{{ $item['id'] }}"
                    x-data="{ itemId: '{{ $item['id'] }}' }"
                    class="flex items-start gap-4 p-4 rounded-xl transition-all duration-300
                        {{ $item['read'] ? 'bg-base-100 border border-base-content/10' : 'bg-base-100 border-l-4 border-primary border-y border-r border-base-content/10' }}"
                    ::class="{
                        'animate-fade-in-down': newItems.has(itemId),
                        'bg-primary/20 border-l-4 border-primary shadow-lg shadow-primary/30': newItems.has(itemId)
                    }"
                >
                    <div class="flex-shrink-0 mt-1">
                        <x-icon
                            :name="$item['icon']"
                            class="w-8 h-8 transition-all duration-300 {{ $item['read'] ? 'text-base-content/50' : 'text-primary' }}"
                            ::class="{ 'scale-125 text-primary drop-shadow-lg': newItems.has(itemId) }"
                        />
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-lg truncate {{ $item['read'] ? 'font-normal text-base-content/70' : 'font-bold text-base-content' }}">
                                {{ $item['title'] }}
                            </span>
                            <span class="text-base text-base-content/50 flex-shrink-0">
                                {{ $item['time_ago'] }}
                            </span>
                        </div>
                        <p class="text-base mt-1 line-clamp-2 {{ $item['read'] ? 'text-base-content/50' : 'text-base-content/80' }}">
                            {{ $item['message'] }}
                        </p>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-16 text-base-content/50">
                    <x-icon name="phosphor-tray" class="w-16 h-16 mb-4" />
                    <p class="text-xl">No activity yet</p>
                </div>
            @endforelse
        </div>
    @else
        {{-- Normal Mode: Compact feed display --}}
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-base-content/70 uppercase tracking-wide">
                {{ $data['feed_label'] }}
            </h3>
            <x-badge value="{{ count($data['items']) }}" class="badge-ghost badge-xs" />
        </div>

        <div class="overflow-y-auto max-h-[350px] space-y-1 pr-1">
            @forelse ($data['items'] as $item)
                <div
                    wire:key="feed-item-{{ $item['id'] }}"
                    x-data="{ itemId: '{{ $item['id'] }}' }"
                    class="flex items-start gap-2 p-2 rounded-lg transition-all duration-200
                        {{ $item['read'] ? 'hover:bg-base-content/5' : 'bg-base-100 border-l-2 border-primary border-y border-r border-base-content/10' }}"
                    ::class="{
                        'animate-fade-in-down': newItems.has(itemId),
                        'bg-primary/20 border-l-2 border-primary shadow-md shadow-primary/20': newItems.has(itemId)
                    }"
                >
                    <div class="flex-shrink-0 mt-0.5">
                        <x-icon
                            :name="$item['icon']"
                            class="w-5 h-5 transition-all duration-200 {{ $item['read'] ? 'text-base-content/40' : 'text-primary' }}"
                            ::class="{ 'scale-110 text-primary drop-shadow': newItems.has(itemId) }"
                        />
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline justify-between gap-1">
                            <span class="text-sm truncate {{ $item['read'] ? 'font-normal text-base-content/60' : 'font-semibold text-base-content' }}">
                                {{ $item['title'] }}
                            </span>
                            <span class="text-xs text-base-content/40 flex-shrink-0">
                                {{ $item['time_ago'] }}
                            </span>
                        </div>
                        <p class="text-xs truncate {{ $item['read'] ? 'text-base-content/40' : 'text-base-content/70' }}">
                            {{ $item['message'] }}
                        </p>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-8 text-base-content/50">
                    <x-icon name="phosphor-tray" class="w-10 h-10 mb-2" />
                    <p class="text-sm">No activity yet</p>
                </div>
            @endforelse
        </div>
    @endif
</div>

    {{-- Last updated timestamp --}}
    <div class="text-xs text-base-content/40 text-right mt-auto pt-2 border-t border-base-content/5">Updated {{ formatTimeAgo($data['last_updated_at'] ?? null) }}</div>
</x-card>

    <style>
        @keyframes fade-in-down {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-in-down {
            animation: fade-in-down 0.3s ease-out;
        }
    </style>
</div>
