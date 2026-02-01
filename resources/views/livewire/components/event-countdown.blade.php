<div
    @if($event) wire:poll.{{ $pollingInterval }}s="updateComponent" @endif
    class="flex flex-col lg:flex-row items-start lg:items-center gap-3 lg:gap-4"
    aria-live="polite"
    aria-label="Event countdown timer"
>
    @if($event)
        {{-- Event Badge and Name --}}
        <div class="flex items-center gap-3">
            <span class="badge {{ $badgeClass }} badge-lg text-base font-bold">
                {{ $state === 'active' ? 'LIVE' : strtoupper($state) }}
            </span>
            <span class="text-xl lg:text-2xl font-bold {{ $textClass }}">
                {{ $event->name }}
            </span>
            @if($state === 'active')
                <span class="relative flex h-4 w-4">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-4 w-4 bg-success"></span>
                </span>
            @endif
        </div>

        {{-- Separator (desktop only) --}}
        <span class="hidden lg:inline text-2xl text-base-content/30">|</span>

        {{-- Countdown Label and Time --}}
        <div class="flex items-center gap-2">
            <span class="text-lg lg:text-xl font-semibold">
                {{ $label }}:
            </span>
            <span class="text-2xl lg:text-3xl font-mono font-bold {{ $textClass }}">
                {{ $this->formattedCountdown }}
            </span>
        </div>

        {{-- Separator (desktop only) --}}
        <span class="hidden lg:inline text-2xl text-base-content/30">|</span>

        {{-- Clocks --}}
        <div class="flex items-center gap-4 text-base lg:text-lg">
            <div class="flex items-center gap-2">
                <span class="text-base-content/70 font-semibold">Local:</span>
                <span class="font-mono font-bold text-lg lg:text-xl">{{ $localTime }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-base-content/70 font-semibold">UTC:</span>
                <span class="font-mono font-bold text-lg lg:text-xl">{{ $utcTime }}</span>
            </div>
        </div>
    @endif
</div>
