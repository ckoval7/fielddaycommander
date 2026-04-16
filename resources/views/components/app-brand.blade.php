<a href="/" wire:navigate class="block">
    <!-- Full brand - shown when sidebar expanded -->
    <div {{ $attributes->class(["hidden-when-collapsed"]) }}>
        <div class="flex items-center gap-3">
            @if($hasCustomLogo)
                <img src="{{ asset($logoPath) }}" alt="Logo" class="w-14 h-14 object-contain">
            @else
                <div class="w-14 h-14 rounded-lg bg-primary/10 flex items-center justify-center">
                    <x-icon name="phosphor-broadcast" class="w-9 h-9 text-primary" />
                </div>
            @endif

            <div class="flex flex-col">
                <span class="font-bold text-xl">
                    {{ $callsign }}
                </span>
                @if($eventName !== $callsign)
                    <span class="text-xs opacity-60">
                        {{ $eventName }}
                    </span>
                @endif
            </div>
        </div>

        @if($tagline)
            <div class="mt-2 text-xs opacity-50">
                {{ $tagline }}
            </div>
        @endif
    </div>

    <!-- Collapsed brand - shown when sidebar collapsed -->
    <div class="display-when-collapsed hidden mx-5 mt-5 mb-1">
        @if($hasCustomLogo)
            <img src="{{ asset($logoPath) }}" alt="Logo" class="w-12 h-12 object-contain">
        @else
            <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center">
                <x-icon name="phosphor-broadcast" class="w-7 h-7 text-primary" />
            </div>
        @endif
    </div>
</a>
