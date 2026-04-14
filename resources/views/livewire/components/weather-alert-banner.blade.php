<div>
    @if($this->isVisible())
        <div class="{{ $manual ? 'alert alert-error' : 'alert alert-warning' }} rounded-none border-0 border-b border-current/20 flex items-start gap-3 py-2 px-4">
            <x-icon name="{{ $manual ? 'o-bolt' : 'o-exclamation-triangle' }}" class="w-5 h-5 shrink-0 mt-0.5" />

            <div class="flex-1 min-w-0">
                @if($manual)
                    <span class="font-semibold text-sm mr-2">Local Alert</span>
                @endif

                <div class="space-y-0.5">
                    @foreach($alerts as $alert)
                        <p class="text-sm font-medium">{{ $alert['headline'] }}</p>
                    @endforeach
                </div>
            </div>

            <button wire:click="dismiss" class="btn btn-ghost btn-xs shrink-0" aria-label="Dismiss alert">
                <x-icon name="o-x-mark" class="w-4 h-4" />
            </button>
        </div>
    @endif
</div>
