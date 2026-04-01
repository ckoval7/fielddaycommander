<div>
@can('verify-bonuses')
    @if($this->event->eventConfiguration && $this->eligibleBonusTypes->isNotEmpty())
        <x-card title="Manual Bonus Claims" shadow>
            <div class="divide-y divide-base-200">
                @foreach($this->eligibleBonusTypes as $bonusType)
                    @php
                        $claimed = $this->claimedBonuses->has($bonusType->id);
                        $bonus = $this->claimedBonuses->get($bonusType->id);
                    @endphp
                    <div class="py-3" wire:key="bonus-{{ $bonusType->id }}">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <input
                                    type="checkbox"
                                    class="checkbox checkbox-sm checkbox-success"
                                    @checked($claimed)
                                    wire:click="{{ $claimed
                                        ? "unclaim({$bonusType->id})"
                                        : "claim({$bonusType->id}, \$wire.notes[{$bonusType->id}])" }}"
                                    @if($claimed) wire:confirm="Remove this bonus claim?" @endif
                                />
                                <div class="min-w-0">
                                    <div class="text-sm font-medium">{{ $bonusType->name }}</div>
                                    <div class="text-xs text-base-content/60 truncate">{{ $bonusType->description }}</div>
                                </div>
                            </div>
                            <span class="text-sm tabular-nums font-semibold shrink-0 {{ $claimed ? 'text-success' : 'text-base-content/40' }}">
                                {{ $claimed ? '+' : '' }}{{ $bonusType->base_points }} pts
                            </span>
                        </div>

                        @if($claimed && $bonus?->notes)
                            <div class="mt-2 ml-9 text-xs text-base-content/60">
                                {{ $bonus->notes }}
                            </div>
                        @elseif(! $claimed)
                            <div class="mt-2 ml-9">
                                <input
                                    type="text"
                                    class="input input-bordered input-xs w-full max-w-sm"
                                    placeholder="{{ $bonusType->code === 'social_media' ? 'Paste social media URL (optional)' : 'Notes (optional)' }}"
                                    wire:model.blur="notes.{{ $bonusType->id }}"
                                />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-card>
    @endif
@endcan
</div>
