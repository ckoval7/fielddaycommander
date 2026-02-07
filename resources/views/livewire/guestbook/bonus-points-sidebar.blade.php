<div wire:poll.10s>
    <x-card title="PR Bonus Points" shadow separator>
        <div class="space-y-4">
            {{-- Category Counts --}}
            <div class="space-y-3">
                {{-- Elected Officials --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="o-building-library" class="w-5 h-5 text-primary" />
                        <span class="text-sm">Elected Officials</span>
                    </div>
                    <x-badge :value="$this->electedOfficialCount" class="badge-primary badge-sm" />
                </div>

                {{-- ARRL Officials --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="o-star" class="w-5 h-5 text-warning" />
                        <span class="text-sm">ARRL Officials</span>
                    </div>
                    <x-badge :value="$this->arrlOfficialCount" class="badge-warning badge-sm" />
                </div>

                {{-- Agency (FEMA, Red Cross) --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-info" />
                        <span class="text-sm">Agency</span>
                    </div>
                    <x-badge :value="$this->agencyCount" class="badge-info badge-sm" />
                </div>

                {{-- Media --}}
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <x-icon name="o-tv" class="w-5 h-5 text-secondary" />
                        <span class="text-sm">Media</span>
                    </div>
                    <x-badge :value="$this->mediaCount" class="badge-secondary badge-sm" />
                </div>
            </div>

            <div class="divider my-2"></div>

            {{-- Progress Bar --}}
            <div class="space-y-2">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium">Verified Visitors</span>
                    <span class="font-mono">{{ $this->totalBonusEligible }} / 10</span>
                </div>

                <x-progress
                    :value="$this->totalBonusEligible"
                    max="10"
                    :class="$this->isMaxBonusReached ? 'progress-success' : 'progress-warning'"
                />

                <div class="text-xs text-base-content/60 text-center">
                    @if($this->isMaxBonusReached)
                        Maximum bonus reached!
                    @else
                        {{ 10 - $this->totalBonusEligible }} more needed for max bonus
                    @endif
                </div>
            </div>

            <div class="divider my-2"></div>

            {{-- Points Display --}}
            <div class="bg-base-200 rounded-lg p-4 text-center">
                <div class="text-xs text-base-content/60 uppercase font-semibold mb-1">Total Bonus Points</div>
                <div class="text-4xl font-bold {{ $this->isMaxBonusReached ? 'text-success' : 'text-warning' }}">
                    {{ $this->bonusPoints }}
                </div>
                <div class="text-xs text-base-content/60 mt-1">
                    100 points per verified visitor (max 10)
                </div>
            </div>

            {{-- Info Alert --}}
            @if(!$this->isMaxBonusReached && $this->totalBonusEligible > 0)
                <x-alert icon="o-information-circle" class="alert-info text-xs">
                    Verify {{ 10 - $this->totalBonusEligible }} more bonus-eligible visitor(s) to maximize points.
                </x-alert>
            @elseif($this->isMaxBonusReached)
                <x-alert icon="o-check-circle" class="alert-success text-xs">
                    Maximum PR bonus points achieved!
                </x-alert>
            @endif
        </div>
    </x-card>
</div>
