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
                                <div class="min-w-0 flex-1">
                                    <x-bonus-rule-help :rule="$this->bonusRule($bonusType->code)">
                                        <span class="text-sm font-medium">{{ $bonusType->name }}</span>
                                    </x-bonus-rule-help>
                                    <div class="text-xs text-base-content/60 truncate">{{ $bonusType->description }}</div>
                                </div>
                            </div>
                            <span class="text-sm tabular-nums font-semibold shrink-0 {{ $claimed ? 'text-success' : 'text-base-content/40' }}">
                                @if($claimed)
                                    +{{ $bonus->calculated_points }} pts
                                @elseif($bonusType->is_per_occurrence && $bonusType->max_occurrences)
                                    {{ $bonusType->base_points }}-{{ $bonusType->max_points }} pts
                                @else
                                    {{ $bonusType->base_points }} pts
                                @endif
                            </span>
                        </div>

                        @if($claimed && $bonus?->notes)
                            <div class="mt-2 ml-9 text-xs text-base-content/60">
                                {{ $bonus->notes }}
                            </div>
                        @elseif(! $claimed)
                            <div class="mt-2 ml-9 flex flex-col gap-2">
                                @if($bonusType->is_per_occurrence && $bonusType->max_occurrences)
                                    <div class="flex items-center gap-2">
                                        <label for="qty-{{ $bonusType->id }}" class="text-xs text-base-content/60 shrink-0">Count:</label>
                                        <input
                                            id="qty-{{ $bonusType->id }}"
                                            type="number"
                                            min="1"
                                            max="{{ $bonusType->max_occurrences }}"
                                            class="input input-bordered input-xs w-20"
                                            wire:model.blur="quantities.{{ $bonusType->id }}"
                                            placeholder="1"
                                        />
                                        <span class="text-xs text-base-content/50">
                                            ({{ $bonusType->base_points }} pts each, max {{ $bonusType->max_occurrences }})
                                        </span>
                                    </div>
                                @endif
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

        {{-- Youth Participation (auto-synced + manual additional) --}}
        @if($this->youthStatus)
            @php $youth = $this->youthStatus; @endphp
            <x-card title="Youth Participation" shadow class="mt-4">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm">Registered youth with QSOs</span>
                        <x-badge :value="$youth['auto_count']" class="{{ $youth['auto_count'] > 0 ? 'badge-success' : 'badge-ghost' }} badge-sm" />
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <label for="additional-youth" class="text-sm">Additional non-account youth</label>
                        <div class="flex items-center gap-2">
                            <input
                                id="additional-youth"
                                type="number"
                                min="0"
                                max="5"
                                class="input input-bordered input-xs w-20"
                                wire:model.blur="additionalYouth"
                                wire:change="saveAdditionalYouth"
                            />
                        </div>
                    </div>

                    <div class="divider my-1"></div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Total youth (max 5)</span>
                        <span class="text-sm tabular-nums font-semibold {{ $youth['points'] > 0 ? 'text-success' : 'text-base-content/40' }}">
                            {{ $youth['total'] }} youth = {{ $youth['points'] > 0 ? '+' : '' }}{{ $youth['points'] }} pts
                        </span>
                    </div>

                    <div class="text-xs text-base-content/50">
                        20 pts per youth (age 18 or younger) who completes at least one QSO. Max 100 pts.
                    </div>
                </div>
            </x-card>
        @endif
    @endif
@endcan
</div>
