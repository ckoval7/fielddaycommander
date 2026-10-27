{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Feed Widget View

Displays a scrollable list of recent activity notifications with animations.
Items new since the previous render get the dashboard-item-new highlight.

Props from component:
- $data: Array with 'items' (feed items), 'feed_type', 'feed_label'
- $size: 'normal' or 'tv'

Each item: id, icon, title, message, time_ago, read
--}}

<div class="h-full" wire:poll.visible.15s>
<x-card class="h-full flex flex-col" shadow>
<div class="flex-1 flex flex-col">
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
                    class="flex items-start gap-4 p-4 rounded-xl transition-all duration-300
                        {{ $item['read'] ? 'bg-base-100 border border-base-content/10' : 'bg-base-100 border-l-4 border-primary border-y border-r border-base-content/10' }} {{ in_array((string) $item['id'], $newItemKeys, true) ? 'dashboard-item-new' : '' }}"
                >
                    <div class="flex-shrink-0 mt-1">
                        <x-icon
                            :name="$item['icon']"
                            class="w-8 h-8 transition-all duration-300 {{ $item['read'] ? 'text-base-content/50' : 'text-primary' }}"
                        />
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-lg truncate {{ $item['read'] ? 'font-normal text-base-content/70' : 'font-bold text-base-content' }}">
                                {{ $item['title'] }}
                            </span>
                            <span class="text-base text-base-content/70 flex-shrink-0">
                                {{ spoken($item['time_ago'], spokenDuration($item['time_ago'])) }}
                            </span>
                        </div>
                        <p class="text-base mt-1 line-clamp-2 {{ $item['read'] ? 'text-base-content/70' : 'text-base-content/80' }}">
                            {{ $item['message'] }}
                        </p>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-16 text-base-content/70">
                    <x-icon name="phosphor-tray" class="w-16 h-16 mb-4" />
                    <p class="text-xl">No notifications yet</p>
                </div>
            @endforelse
        </div>
    @else
        {{-- Normal Mode: Compact feed display --}}
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-sm font-semibold text-base-content/70 uppercase tracking-wide">
                {{ $data['feed_label'] }}
            </h2>
            <x-badge value="{{ count($data['items']) }}" class="badge-ghost badge-xs" />
        </div>

        <div class="overflow-y-auto max-h-[350px] space-y-1 pr-1">
            @forelse ($data['items'] as $item)
                <div
                    wire:key="feed-item-{{ $item['id'] }}"
                    class="flex items-start gap-2 p-2 rounded-lg transition-all duration-200
                        {{ $item['read'] ? 'hover:bg-base-content/5' : 'bg-base-100 border-l-2 border-primary border-y border-r border-base-content/10' }} {{ in_array((string) $item['id'], $newItemKeys, true) ? 'dashboard-item-new' : '' }}"
                >
                    <div class="flex-shrink-0 mt-0.5">
                        <x-icon
                            :name="$item['icon']"
                            class="w-5 h-5 transition-all duration-200 {{ $item['read'] ? 'text-base-content/40' : 'text-primary' }}"
                        />
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline justify-between gap-1">
                            <span class="text-sm truncate {{ $item['read'] ? 'font-normal text-base-content/60' : 'font-semibold text-base-content' }}">
                                {{ $item['title'] }}
                            </span>
                            <span class="text-xs text-base-content/70 flex-shrink-0">
                                {{ spoken($item['time_ago'], spokenDuration($item['time_ago'])) }}
                            </span>
                        </div>
                        <p class="text-xs truncate {{ $item['read'] ? 'text-base-content/70' : 'text-base-content/70' }}">
                            {{ $item['message'] }}
                        </p>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-8 text-base-content/70">
                    <x-icon name="phosphor-tray" class="w-10 h-10 mb-2" />
                    <p class="text-sm">No notifications yet</p>
                </div>
            @endforelse
        </div>
    @endif
</div>

    {{-- Last updated timestamp --}}
    <x-dashboard.updated-at :at="$data['last_updated_at'] ?? null" class="border-t border-base-content/5" />
</x-card>

</div>
