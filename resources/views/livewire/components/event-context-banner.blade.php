<div>
    @if($isViewingPast && $contextEvent)
        <div class="alert {{ $gracePeriodStatus === 'grace' ? 'alert-warning' : 'alert-info' }} mb-4">
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <x-icon name="phosphor-info" class="w-5 h-5" />
                    <span>
                        <strong>Viewing:</strong> {{ $contextEvent->name }}
                        @if($gracePeriodStatus === 'grace')
                            <x-badge value="Grace Period" class="badge-warning badge-sm ml-1" />
                            <span class="text-sm opacity-75">&mdash; {{ $graceDaysRemaining }} days remaining</span>
                        @elseif($gracePeriodStatus === 'archived')
                            <x-badge value="Read Only" class="badge-ghost badge-sm ml-1" />
                        @endif
                    </span>
                </div>

                @if($activeEvent)
                    <button wire:click="returnToActive" class="btn btn-sm btn-ghost">
                        Return to Active Event
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
