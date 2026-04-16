<div>
    @if($this->isVisible())
        <div class="relative">
            @foreach($alerts as $alert)
                @php
                    $isManual = ($alert['event'] ?? '') === 'Local Alert';
                    $isRed = $isManual || ($alert['severity_level'] ?? '') === 'red';
                @endphp
                <div class="{{ $isRed ? 'alert alert-error' : 'alert bg-amber-100 text-amber-950 dark:bg-yellow-400/20 dark:text-yellow-100' }} rounded-none border-0 border-b border-current/20 flex items-start gap-3 py-2 px-4 pr-10">
                    <x-icon name="{{ $isRed ? 'phosphor-warning-octagon-duotone' : 'phosphor-warning-duotone' }}" class="w-5 h-5 shrink-0 mt-0.5" />

                    <div class="flex-1 min-w-0">
                        <span class="font-semibold text-sm mr-2">
                            @if($isManual)
                                Local Alert
                            @elseif($isRed)
                                Immediate Danger
                            @else
                                Nearby Danger
                            @endif
                        </span>
                        <p class="text-sm font-medium">{{ $alert['headline'] }}</p>
                    </div>
                </div>
            @endforeach

            <button wire:click="dismiss" class="btn btn-ghost btn-xs absolute top-1 right-1" aria-label="Dismiss alerts">
                <x-icon name="phosphor-x" class="w-4 h-4" />
            </button>
        </div>
    @endif
</div>
