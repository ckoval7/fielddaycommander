<div>
    <div class="card bg-base-100 shadow border border-error/20">
        <div class="card-body items-center text-center py-8">
            <x-mary-icon name="phosphor-warning" class="w-10 h-10 text-error/50" />
            <h3 class="font-semibold text-base-content/70 mt-2">{{ $widgetName }}</h3>
            <p class="text-sm text-base-content/50">Failed to load widget</p>
            @if (config('app.debug') && $errorMessage)
                <div class="mt-3 p-3 bg-error/5 rounded-lg text-left w-full">
                    <p class="text-xs font-mono text-error/70 break-all">{{ $errorMessage }}</p>
                </div>
            @endif
        </div>
    </div>
</div>
