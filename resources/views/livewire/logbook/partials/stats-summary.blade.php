{{-- Stats Summary Component --}}
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
    {{-- Total QSOs --}}
    <x-card class="shadow-md">
        <div class="text-center">
            <div class="text-xs sm:text-sm text-base-content/70 uppercase tracking-wide">Total QSOs</div>
            <div class="text-2xl sm:text-3xl font-bold text-primary mt-1">{{ number_format($this->stats['total_qsos']) }}</div>
        </div>
    </x-card>

    {{-- Total Points --}}
    <x-card class="shadow-md">
        <div class="text-center">
            <div class="text-xs sm:text-sm text-base-content/70 uppercase tracking-wide">Total Points</div>
            <div class="text-2xl sm:text-3xl font-bold text-success mt-1">{{ number_format($this->stats['total_points']) }}</div>
        </div>
    </x-card>

    {{-- Unique Sections --}}
    <x-card class="shadow-md">
        <div class="text-center">
            <div class="text-xs sm:text-sm text-base-content/70 uppercase tracking-wide">Sections</div>
            <div class="text-2xl sm:text-3xl font-bold text-accent mt-1">{{ $this->stats['unique_sections'] }}</div>
        </div>
    </x-card>

    {{-- QSOs by Mode --}}
    <x-card class="shadow-md col-span-2 md:col-span-3 lg:col-span-3">
        <div class="p-2 sm:p-3">
            <div class="text-xs sm:text-sm text-base-content/70 uppercase tracking-wide mb-3 text-center lg:text-left">
                QSOs by Mode
            </div>
            @if(empty($this->stats['by_mode']))
                <div class="text-center text-sm text-base-content/50 py-2">
                    No contacts yet
                </div>
            @else
                <div class="grid grid-cols-3 gap-2">
                    @foreach($this->stats['by_mode'] as $mode => $count)
                        <div class="flex flex-col items-center p-2 bg-base-200 rounded-lg">
                            <span class="text-xs text-base-content/60">{{ $mode }}</span>
                            <span class="text-lg font-bold text-success">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-card>
</div>

{{-- Second Row: QSOs by Band --}}
<div class="grid grid-cols-1 gap-4 mt-4">
    <x-card class="shadow-md">
        <div class="p-2 sm:p-3">
            <div class="text-xs sm:text-sm text-base-content/70 uppercase tracking-wide mb-3 text-center lg:text-left">
                QSOs by Band
            </div>
            @if(empty($this->stats['by_band']))
                <div class="text-center text-sm text-base-content/50 py-2">
                    No contacts yet
                </div>
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2">
                    @foreach($this->stats['by_band'] as $band => $count)
                        <div class="flex flex-col items-center p-2 bg-base-200 rounded-lg">
                            <span class="text-xs text-base-content/60">{{ $band }}</span>
                            <span class="text-lg font-bold text-primary">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-card>
</div>
