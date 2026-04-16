<div
    x-data="{
        selectedLayout: @entangle('selectedLayout').live,
        open: false,
        init() {
            // Load saved layout from localStorage on mount
            const saved = localStorage.getItem('dashboard_layout');
            if (saved && saved !== this.selectedLayout) {
                this.selectedLayout = saved;
                @this.switchLayout(saved);
            }

            // Watch for layout changes and save to localStorage
            this.$watch('selectedLayout', (value) => {
                localStorage.setItem('dashboard_layout', value);
            });
        }
    }"
    @click.away="open = false"
    class="relative"
>
    {{-- Dropdown Button --}}
    <button
        @click="open = !open"
        type="button"
        class="btn btn-ghost btn-sm gap-2"
        data-cy="layout-selector"
        aria-label="Select Dashboard Layout"
        aria-haspopup="true"
        :aria-expanded="open"
    >
        <x-icon name="phosphor-squares-four" class="w-5 h-5" />
        <span class="hidden sm:inline">
            {{ collect($layouts)->firstWhere('key', $selectedLayout)['name'] ?? 'Dashboard' }}
        </span>
        <x-icon name="phosphor-caret-down" class="w-4 h-4" />
    </button>

    {{-- Dropdown Menu --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95"
        x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute right-0 mt-2 w-64 rounded-lg shadow-lg bg-base-100 border border-base-300 z-50"
        role="menu"
        aria-orientation="vertical"
    >
        <div class="p-2">
            <div class="px-3 py-2 text-xs font-semibold text-base-content/70 uppercase tracking-wide">
                Dashboard Layout
            </div>
            @foreach($layouts as $layout)
                <button
                    wire:click="switchLayout('{{ $layout['key'] }}')"
                    @click="open = false"
                    type="button"
                    class="w-full text-left px-3 py-2 rounded-md hover:bg-base-200 transition-colors duration-150
                        {{ $selectedLayout === $layout['key'] ? 'bg-primary/10 text-primary font-semibold' : 'text-base-content' }}"
                    data-cy="layout-{{ $layout['key'] }}"
                    role="menuitem"
                >
                    <div class="flex items-start gap-2">
                        <x-icon
                            :name="$selectedLayout === $layout['key'] ? 'phosphor-check-circle' : 'phosphor-squares-four'"
                            class="w-5 h-5 mt-0.5 {{ $selectedLayout === $layout['key'] ? 'text-primary' : 'text-base-content/50' }}"
                        />
                        <div class="flex-1 min-w-0">
                            <div class="font-medium">{{ $layout['name'] }}</div>
                            @if(!empty($layout['description']))
                                <div class="text-xs text-base-content/60 mt-0.5">
                                    {{ $layout['description'] }}
                                </div>
                            @endif
                        </div>
                    </div>
                </button>
            @endforeach
        </div>
    </div>
</div>
