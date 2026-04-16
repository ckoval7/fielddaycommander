<div wire:poll.15m="loadWeatherData">
    @php
        $shouldShow = $hasData || $canManageWeather;
        $isGrayedOut = ! $hasData && $canManageWeather;
    @endphp

    @if($shouldShow)
        <a
            wire:navigate
            href="{{ route('weather.index') }}"
            class="flex items-center gap-1.5 btn btn-ghost btn-sm px-2 {{ $isGrayedOut ? 'opacity-40' : '' }}"
            title="Weather"
        >
            <x-icon name="{{ $this->iconName() }}" class="w-5 h-5" />

            @if($hasData && $temperature !== null)
                <span class="text-sm font-medium tabular-nums">{{ round($temperature) }}°</span>
            @endif

            @if($isManual && $hasData)
                <span class="badge badge-warning badge-xs font-bold">M</span>
            @endif

            @if($hasData && $gusts !== null && $gusts >= 25)
                <span class="badge badge-warning badge-xs">
                    <x-icon name="phosphor-lightning-duotone" class="w-3 h-3" />
                </span>
            @endif
        </a>
    @endif
</div>
