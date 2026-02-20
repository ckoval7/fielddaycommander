<div
    wire:poll.30s="loadNotifications"
    x-data="{ open: false }"
    @click.away="open = false"
    class="relative"
>
    {{-- Bell Button --}}
    <button
        @click="open = !open"
        class="btn btn-ghost btn-circle btn-sm relative"
        aria-label="Notifications"
    >
        <x-icon name="o-bell" class="w-5 h-5" />
        @if($unreadCount > 0)
            <span class="absolute -top-1 -right-1 badge badge-error badge-xs text-white font-bold min-w-[1.25rem] h-5 flex items-center justify-center">
                {{ $unreadCount > 99 ? '99+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- Dropdown Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="absolute right-0 mt-2 w-80 bg-base-100 rounded-box shadow-xl border border-base-300 z-[100] overflow-hidden"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between px-4 py-3 border-b border-base-300">
            <h3 class="font-bold text-sm">Notifications</h3>
            @if($unreadCount > 0)
                <button
                    wire:click="markAllAsRead"
                    class="text-xs text-primary hover:text-primary-focus font-medium cursor-pointer"
                >
                    Mark all as read
                </button>
            @endif
        </div>

        {{-- Notification List --}}
        <div class="max-h-80 overflow-y-auto">
            @forelse($notifications as $notification)
                <button
                    wire:click="openNotification('{{ $notification->id }}')"
                    class="w-full text-left px-4 py-3 hover:bg-base-200 transition-colors border-b border-base-300/50 last:border-b-0 flex items-start gap-3 cursor-pointer {{ is_null($notification->read_at) ? 'bg-primary/5' : '' }}"
                >
                    {{-- Icon --}}
                    <div class="flex-shrink-0 mt-0.5">
                        <x-icon
                            name="{{ $notification->data['icon'] ?? 'o-bell' }}"
                            class="w-5 h-5 {{ is_null($notification->read_at) ? 'text-primary' : 'text-base-content/40' }}"
                        />
                    </div>

                    {{-- Content --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <p class="text-sm font-medium {{ is_null($notification->read_at) ? 'text-base-content' : 'text-base-content/70' }} truncate">
                                {{ $notification->data['title'] ?? 'Notification' }}
                            </p>
                            @if(($notification->data['count'] ?? 1) > 1)
                                <span class="flex-shrink-0 text-xs font-bold text-primary bg-primary/10 rounded px-1 leading-5">
                                    ×{{ $notification->data['count'] }}
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-base-content/60 truncate mt-0.5">
                            {{ $notification->data['message'] ?? '' }}
                        </p>
                        <p class="text-xs text-base-content/40 mt-1">
                            {{ $notification->created_at->diffForHumans() }}
                        </p>
                    </div>

                    {{-- Unread dot --}}
                    @if(is_null($notification->read_at))
                        <div class="flex-shrink-0 mt-2">
                            <span class="block w-2 h-2 rounded-full bg-primary"></span>
                        </div>
                    @endif
                </button>
            @empty
                <div class="px-4 py-8 text-center">
                    <x-icon name="o-bell-slash" class="w-8 h-8 mx-auto text-base-content/30 mb-2" />
                    <p class="text-sm text-base-content/50">No notifications yet</p>
                </div>
            @endforelse
        </div>

        {{-- Footer --}}
        <div class="border-t border-base-300 px-4 py-2">
            <a
                href="{{ route('profile') }}"
                wire:navigate
                class="text-xs text-primary hover:text-primary-focus font-medium"
            >
                Notification Settings
            </a>
        </div>
    </div>
</div>
