<div wire:poll.10s>
    <x-card title="Guestbook Bonuses" shadow separator>
        <div class="space-y-4">
            {{-- Bonus Items --}}
            <div class="space-y-3">
                @foreach($this->bonusItems as $key => $item)
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                            @if($item['earned'])
                                <x-icon name="o-check-circle" class="w-5 h-5 text-success shrink-0" />
                            @else
                                <x-icon :name="$item['icon']" class="w-5 h-5 {{ $item['iconColor'] }} shrink-0" />
                            @endif
                            <div class="min-w-0">
                                <span class="text-sm">{{ $item['label'] }}</span>
                                <span class="text-xs text-base-content/50 ml-1">({{ $item['rule'] }})</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <x-badge :value="$item['count']" class="{{ $item['earned'] ? 'badge-success' : 'badge-ghost' }} badge-sm" />
                            <span class="text-sm tabular-nums font-semibold {{ $item['earned'] ? 'text-success' : 'text-base-content/40' }}">
                                {{ $item['earned'] ? '+' : '' }}{{ $item['points'] }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ARRL Officials (tracked but not bonus-eligible) --}}
            @if($this->arrlOfficialCount > 0)
                <div class="flex items-center justify-between text-base-content/60">
                    <div class="flex items-center gap-2">
                        <x-icon name="o-star" class="w-5 h-5 text-warning" />
                        <span class="text-sm">ARRL Officials</span>
                    </div>
                    <x-badge :value="$this->arrlOfficialCount" class="badge-warning badge-sm" />
                </div>
                <div class="text-xs text-base-content/50 -mt-2 ml-7">Tracked but not bonus-eligible per rules</div>
            @endif

            <div class="divider my-2"></div>

            {{-- Points Summary --}}
            <div class="bg-base-200 rounded-lg p-4 text-center">
                <div class="text-xs text-base-content/60 uppercase font-semibold mb-1">Guestbook Bonus Points</div>
                <div class="text-4xl font-bold {{ $this->totalBonusPoints === $this->maxBonusPoints ? 'text-success' : 'text-warning' }}">
                    {{ $this->totalBonusPoints }}
                </div>
                <div class="text-xs text-base-content/60 mt-1">
                    of {{ $this->maxBonusPoints }} possible
                </div>
            </div>

            @if($this->totalBonusPoints < $this->maxBonusPoints)
                @php
                    $missing = collect($this->bonusItems)->where('earned', false)->pluck('label')->join(', ');
                @endphp
                <x-alert icon="o-information-circle" class="alert-info text-xs">
                    Still needed: {{ $missing }}
                </x-alert>
            @elseif($this->totalBonusPoints === $this->maxBonusPoints)
                <x-alert icon="o-check-circle" class="alert-success text-xs">
                    All guestbook bonuses earned!
                </x-alert>
            @endif
        </div>
    </x-card>
</div>
