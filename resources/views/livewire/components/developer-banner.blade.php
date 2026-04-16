<div @if($isVisible && !$isFrozen) wire:poll.10s="refreshTime" @endif>
    @if($isVisible)
        <div class="alert bg-amber-100 border-l-4 border-amber-500 text-amber-900 shadow-md rounded-none" role="alert">
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 sm:gap-4 w-full">
                {{-- Left: Warning Icon + Label + Divider --}}
                <div class="flex items-center gap-3 shrink-0">
                    <x-icon name="phosphor-warning" class="w-5 h-5 text-amber-600" />
                    <span class="font-bold text-sm sm:text-base">DEVELOPER MODE</span>
                    <span class="hidden sm:inline text-amber-400">|</span>
                </div>

                {{-- Center: Clock Info --}}
                <div class="flex items-center gap-2 min-w-0 flex-1">
                    <x-icon name="phosphor-clock" class="w-4 h-4 text-amber-600 shrink-0" />
                    <span class="font-mono text-sm sm:text-base truncate">
                        {{ $fakeTime?->timezone('UTC')->format('F j, Y H:i:s') }} UTC
                    </span>
                    <span class="badge badge-sm {{ $isFrozen ? 'badge-warning' : 'badge-info' }} shrink-0">
                        {{ $isFrozen ? 'frozen' : 'flowing' }}
                    </span>
                </div>

                {{-- Right: Configure Link and Dismiss Button --}}
                <div class="flex items-center gap-3 shrink-0 ml-auto">
                    @can('manage-settings')
                        <a
                            href="{{ route('admin.developer') }}"
                            class="link link-hover font-semibold text-sm sm:text-base"
                            wire:navigate
                        >
                            Configure
                        </a>
                    @endcan
                    <button
                        wire:click="dismiss"
                        type="button"
                        class="btn btn-ghost btn-xs btn-circle"
                        aria-label="Dismiss developer mode banner"
                    >
                        <x-icon name="phosphor-x" class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
