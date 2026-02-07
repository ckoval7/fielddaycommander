<div>
    {{-- Sticky Session Info Bar --}}
    <div class="sticky top-0 z-30 bg-base-100 border-b border-base-300 shadow-sm">
        <div class="px-4 py-2.5 flex items-center justify-between gap-3">
            {{-- Left: Station name --}}
            <div class="min-w-0">
                <span class="font-bold text-lg sm:text-xl truncate block">{{ $operatingSession->station->name ?? 'Station' }}</span>
            </div>

            {{-- Center: Band / Mode / Power --}}
            <div class="hidden sm:flex items-center gap-1.5 text-base font-mono tracking-wide">
                <span class="font-semibold">{{ $operatingSession->band->name ?? '?' }}</span>
                <span class="text-base-content/30">|</span>
                <span class="font-semibold">{{ $operatingSession->mode->name ?? '?' }}</span>
                <span class="text-base-content/30">|</span>
                <span class="text-base-content/70">{{ $operatingSession->power_watts }}W</span>
            </div>

            {{-- Right: QSO counter + End button --}}
            <div class="flex items-center gap-3 flex-shrink-0">
                <div class="flex items-center gap-1">
                    <span class="text-xs uppercase tracking-wider text-base-content/50 hidden sm:inline">QSOs</span>
                    <span class="font-mono font-bold text-xl sm:text-2xl tabular-nums">{{ $operatingSession->qso_count }}</span>
                </div>
                <x-button
                    label="End Session"
                    icon="o-stop"
                    class="btn-outline btn-error btn-sm"
                    wire:click="endSession"
                    wire:confirm="Are you sure you want to end this session?"
                />
            </div>
        </div>

        {{-- Mobile-only compact row for band/mode/power --}}
        <div class="sm:hidden flex items-center justify-center gap-2 px-4 pb-2 text-sm font-mono text-base-content/60">
            <span>{{ $operatingSession->band->name ?? '?' }}</span>
            <span class="text-base-content/20">&middot;</span>
            <span>{{ $operatingSession->mode->name ?? '?' }}</span>
            <span class="text-base-content/20">&middot;</span>
            <span>{{ $operatingSession->power_watts }}W</span>
        </div>
    </div>

    <div class="px-4 py-4 max-w-4xl mx-auto space-y-4">
        {{-- Your Exchange --}}
        <div class="text-center">
            <span class="text-base-content/70 text-sm uppercase tracking-wider">Your Exchange</span>
            <div class="text-2xl font-bold font-mono">{{ $this->clubExchange }}</div>
            <div class="text-sm text-base-content/60 italic">{{ $this->phoneticExchange }}</div>
        </div>

        {{-- Band/Mode Info --}}
        <div class="text-center text-xs text-base-content/50">
            To change band or mode, end this session and start a new one.
        </div>

        {{-- Exchange Input --}}
        <div class="space-y-2" x-data="{ si: -1 }">
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="exchangeInput"
                        x-ref="exchangeInput"
                        @input="si = -1"
                        @keydown.enter.prevent="si >= 0 && $wire.suggestions.length > 0 ? ($wire.selectSuggestion($wire.suggestions[si].exchange), si = -1) : $wire.logContact()"
                        @keydown.escape.prevent="si >= 0 ? (si = -1) : $wire.clearInput()"
                        @keydown.arrow-down.prevent="si = Math.min(si + 1, {{ count($suggestions) }} - 1)"
                        @keydown.arrow-up.prevent="si = Math.max(si - 1, -1)"
                        @keydown.tab.prevent="si >= 0 && $wire.suggestions.length > 0 ? ($wire.selectSuggestion($wire.suggestions[si].exchange), si = -1) : null"
                        @contact-logged.window="$refs.exchangeInput.focus(); $refs.exchangeInput.select(); si = -1"
                        @suggestion-selected.window="$nextTick(() => { $refs.exchangeInput.focus(); si = -1 })"
                        class="input input-bordered input-lg w-full text-2xl font-mono uppercase tracking-wider"
                        placeholder="W1AW 3A CT"
                        autofocus
                    />

                    {{-- Autocomplete Suggestions --}}
                    @if(count($suggestions) > 0)
                        <div class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-box shadow-lg max-h-48 overflow-y-auto">
                            @foreach($suggestions as $index => $suggestion)
                                <button
                                    wire:click="selectSuggestion('{{ $suggestion['exchange'] }}')"
                                    :class="{ 'bg-primary text-primary-content': si === {{ $index }} }"
                                    @mouseenter="si = {{ $index }}"
                                    class="w-full px-3 py-2 text-left hover:bg-base-200 flex items-center justify-between font-mono"
                                    type="button"
                                >
                                    <span class="font-bold">{{ $suggestion['exchange'] }}</span>
                                    <span class="text-xs opacity-60" :class="{ 'text-primary-content/60': si === {{ $index }} }">{{ $suggestion['worked_on'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <x-button
                    label="Log"
                    icon="o-check"
                    class="btn-primary btn-lg"
                    wire:click="logContact"
                    spinner="logContact"
                    tooltip="Enter"
                    tooltip-position="tooltip-bottom"
                />
                <x-button
                    label="Clear"
                    icon="o-x-mark"
                    class="btn-ghost btn-lg"
                    wire:click="clearInput"
                    tooltip="Esc"
                    tooltip-position="tooltip-bottom"
                />
            </div>

            @if($parseError)
                <x-alert icon="o-exclamation-triangle" class="alert-error">
                    {{ $parseError }}
                </x-alert>
            @endif

            @if($isDuplicate)
                <x-alert icon="o-exclamation-triangle" class="alert-warning">
                    Duplicate: {{ $dupeWarning }}
                </x-alert>
            @endif

            {{-- Keyboard Shortcuts Help --}}
            <div class="flex flex-wrap gap-x-4 gap-y-1 justify-center text-xs text-base-content/40">
                <span><kbd class="kbd kbd-xs">Enter</kbd> Log contact</span>
                <span><kbd class="kbd kbd-xs">Esc</kbd> Clear input</span>
                <span><kbd class="kbd kbd-xs">&uarr;</kbd><kbd class="kbd kbd-xs">&darr;</kbd> Navigate suggestions</span>
                <span><kbd class="kbd kbd-xs">Tab</kbd> Accept suggestion</span>
            </div>
        </div>

        {{-- Recent QSOs --}}
        <x-card title="Recent QSOs" subtitle="This session only">
            @if($this->recentContacts->isEmpty())
                <div class="text-center py-4 text-base-content/50 space-y-1">
                    <p>No contacts logged yet.</p>
                    <p class="text-xs">Type the other station's exchange above, e.g. <span class="font-mono font-bold">W1AW 3A CT</span></p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Time</th>
                                <th>Callsign</th>
                                <th>Exchange</th>
                                <th>Section</th>
                                <th>Pts</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($this->recentContacts as $contact)
                                <tr wire:key="contact-{{ $contact->id }}" @class(['opacity-50' => $contact->is_duplicate])>
                                    <td class="font-mono">{{ $operatingSession->qso_count - $loop->index }}</td>
                                    <td class="font-mono">{{ $contact->qso_time->format('H:i') }}</td>
                                    <td class="font-bold font-mono uppercase">{{ $contact->callsign }}</td>
                                    <td class="font-mono">{{ $contact->received_exchange }}</td>
                                    <td>{{ $contact->section->code ?? '-' }}</td>
                                    <td class="font-mono">
                                        @if($contact->is_duplicate)
                                            <x-badge value="DUPE" class="badge-xs badge-warning" />
                                        @else
                                            {{ $contact->points }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</div>
