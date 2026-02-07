<a href="/" wire:navigate class="block">
    <!-- Full brand - shown when sidebar expanded -->
    <div {{ $attributes->class(["hidden-when-collapsed"]) }}>
        <div class="flex items-center gap-3">
            @if(file_exists(public_path($logoPath)))
                <img src="{{ asset($logoPath) }}" alt="Logo" class="w-12 h-12 object-contain">
            @else
                <div class="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center">
                    <x-icon name="o-signal" class="w-7 h-7 text-primary" />
                </div>
            @endif

            <div class="flex flex-col">
                <span class="font-bold text-lg">
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
        @if(file_exists(public_path($logoPath)))
            <img src="{{ asset($logoPath) }}" alt="Logo" class="w-10 h-10 object-contain">
        @else
            <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                <x-icon name="o-signal" class="w-6 h-6 text-primary" />
            </div>
        @endif
    </div>
</a>
