{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
@can('verify-bonuses')
    @if($this->event->eventConfiguration && $this->classEligibleBonusTypes->isNotEmpty())
        <x-card :title="$this->terms->claimsTitle" shadow>
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
                                    aria-label="Claim {{ strtolower($this->terms->awardSingular) }}: {{ spokenAbbreviations($bonusType->name) }}"
                                    wire:key="bonus-checkbox-{{ $bonusType->id }}-{{ $claimed ? 'claimed' : 'unclaimed' }}"
                                    @checked($claimed)
                                    @if($claimed)
                                        x-on:click.prevent="$confirm({ message: {{ Js::from('Remove this '.strtolower($this->terms->awardSingular).' claim?') }}, confirmLabel: 'Remove', danger: true }).then((confirmed) => confirmed && $wire.unclaim({{ $bonusType->id }}))"
                                    @else
                                        x-on:click.prevent="$wire.claim({{ $bonusType->id }}, $wire.notes[{{ $bonusType->id }}])"
                                    @endif
                                />
                                <div class="min-w-0 flex-1">
                                    <x-bonus-rule-help :rulebook="$this->terms->rulebookName" :rule="$this->bonusRule($bonusType->code)">
                                        <span class="text-sm font-medium">{{ spoken($bonusType->name, spokenAbbreviations($bonusType->name)) }}</span>
                                    </x-bonus-rule-help>
                                    <div class="text-xs text-base-content/60 truncate">{{ $bonusType->description }}</div>
                                </div>
                            </div>
                            <span class="text-sm tabular-nums font-semibold shrink-0 {{ $claimed ? 'text-success' : 'text-base-content/70' }}">
                                @if($claimed)
                                    @if($bonusType->objective_multiplier !== null)
                                        OM ×{{ $bonusType->objective_multiplier }}
                                    @else
                                        +{{ $bonus->calculated_points }} <x-points-unit />
                                    @endif
                                @elseif($bonusType->objective_multiplier !== null)
                                    OM ×{{ $bonusType->objective_multiplier }}
                                @elseif($bonusType->is_per_occurrence && $bonusType->max_occurrences)
                                    {{ $bonusType->base_points }}-{{ $bonusType->max_points }} <x-points-unit />
                                @else
                                    {{ $bonusType->base_points }} <x-points-unit />
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
                                            class="input input-xs w-20"
                                            wire:model.blur="quantities.{{ $bonusType->id }}"
                                            placeholder="1"
                                        />
                                        <span class="text-xs text-base-content/70">
                                            ({{ $bonusType->base_points }} <x-points-unit /> each, max {{ $bonusType->max_occurrences }})
                                        </span>
                                    </div>
                                @endif
                                <input
                                    type="text"
                                    class="input input-xs w-full max-w-sm"
                                    placeholder="{{ $bonusType->code === 'social_media' ? 'Paste social media URL (optional)' : 'Notes (optional)' }}"
                                    aria-label="Notes for {{ spokenAbbreviations($bonusType->name) }}"
                                    wire:model.blur="notes.{{ $bonusType->id }}"
                                />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($this->automaticBonusTypes->isNotEmpty())
                {{-- Awards the app earns on its own, listed so the card shows the full set --}}
                <div @class(['mt-4 pt-4 border-t border-base-300' => $this->eligibleBonusTypes->isNotEmpty()])>
                    <h3 class="text-sm font-semibold">Earned automatically</h3>
                    <p class="text-xs text-base-content/70">These are tracked for you. There's nothing to claim here.</p>
                    <div class="divide-y divide-base-200">
                        @foreach($this->automaticBonusTypes as $bonusType)
                            @php $earnedPoints = $this->automaticEarnings[$bonusType->id] ?? null; @endphp
                            <div class="py-3" wire:key="auto-bonus-{{ $bonusType->id }}" data-automatic-bonus="{{ $bonusType->code }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-3 flex-1 min-w-0">
                                        @if($earnedPoints !== null)
                                            <x-icon name="phosphor-check-circle" class="w-5 h-5 pointer-coarse:w-6 pointer-coarse:h-6 shrink-0 mt-0.5 text-success" />
                                        @else
                                            <x-icon name="phosphor-circle" class="w-5 h-5 pointer-coarse:w-6 pointer-coarse:h-6 shrink-0 mt-0.5 text-base-content/40" />
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <x-bonus-rule-help :rulebook="$this->terms->rulebookName" :rule="$this->bonusRule($bonusType->code)">
                                                <span class="text-sm font-medium text-base-content/70">{{ spoken($bonusType->name, spokenAbbreviations($bonusType->name)) }}</span>
                                            </x-bonus-rule-help>
                                            <p class="text-xs text-base-content/70">{{ $this->automaticHelp($bonusType->code) }}</p>
                                        </div>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <span class="block text-sm tabular-nums font-semibold {{ $earnedPoints !== null ? 'text-success' : 'text-base-content/60' }}">
                                            @if($bonusType->objective_multiplier !== null)
                                                OM ×{{ $bonusType->objective_multiplier }}
                                            @elseif($earnedPoints !== null)
                                                +{{ $earnedPoints }} <x-points-unit />
                                            @elseif($bonusType->is_per_occurrence && $bonusType->max_occurrences)
                                                {{ $bonusType->base_points }}-{{ $bonusType->max_points }} <x-points-unit />
                                            @else
                                                {{ $bonusType->base_points }} <x-points-unit />
                                            @endif
                                        </span>
                                        <span class="block text-xs text-base-content/70">{{ $earnedPoints !== null ? 'Earned' : 'Not yet' }}</span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
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

                    @if($this->eventOperators->isNotEmpty())
                        <details class="collapse collapse-arrow bg-base-200/50 rounded-box">
                            <summary class="collapse-title text-sm min-h-0 py-2 pointer-coarse:py-3">Operators in this event ({{ $this->eventOperators->count() }})</summary>
                            <div class="collapse-content">
                                <ul class="divide-y divide-base-300">
                                    @foreach($this->eventOperators as $operator)
                                        <li wire:key="youth-operator-{{ $operator->id }}">
                                            <label class="flex items-center justify-between gap-3 py-1.5 cursor-pointer">
                                                <span class="text-sm">
                                                    <span class="font-mono">{{ $operator->call_sign }}</span>
                                                    <span class="text-base-content/60">{{ trim($operator->first_name.' '.$operator->last_name) }}</span>
                                                </span>
                                                <span class="flex items-center gap-2 text-xs text-base-content/60">
                                                    Youth
                                                    <input
                                                        type="checkbox"
                                                        class="toggle toggle-sm toggle-success"
                                                        @checked($operator->is_youth)
                                                        wire:click="toggleOperatorYouth({{ $operator->id }})"
                                                        aria-label="Youth (age 18 or younger): {{ $operator->call_sign }}"
                                                    />
                                                </span>
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </details>
                    @endif

                    <div class="flex items-center justify-between gap-3">
                        <label for="additional-youth" class="text-sm">
                            Youth with no account
                            <span class="block text-xs text-base-content/70">Not in the log under their own callsign</span>
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                id="additional-youth"
                                type="number"
                                min="0"
                                max="5"
                                class="input input-xs w-20"
                                wire:model.blur="additionalYouth"
                                wire:change="saveAdditionalYouth"
                            />
                        </div>
                    </div>

                    <div class="divider my-1"></div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Total youth (max 5)</span>
                        <span class="text-sm tabular-nums font-semibold {{ $youth['points'] > 0 ? 'text-success' : 'text-base-content/70' }}">
                            {{ $youth['total'] }} youth = {{ $youth['points'] > 0 ? '+' : '' }}{{ $youth['points'] }} <x-points-unit />
                        </span>
                    </div>

                    <div class="text-xs text-base-content/70">
                        20 <x-points-unit /> per youth (age 18 or younger) who completes at least one QSO. Max 100 <x-points-unit />.
                    </div>
                </div>
            </x-card>
        @endif
    @endif
@endcan
</div>
