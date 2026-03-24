<div>
    @if(! $this->event)
        {{-- Archived / No Event State --}}
        <div class="max-w-2xl mx-auto px-4 py-16 text-center space-y-6">
            <div class="text-6xl">📋</div>
            <div>
                <h2 class="text-2xl font-bold mb-2">This event is archived</h2>
                <p class="text-base-content/60">Transcription is only available for active or grace-period events.</p>
            </div>
            <a href="{{ route('logging.transcribe.select') }}" class="btn btn-primary" wire:navigate>
                &larr; Back to Station Select
            </a>
        </div>
    @else
        {{-- Working Time Bar — sticky at top --}}
        <div class="sticky top-0 z-40 bg-amber-50 dark:bg-amber-900/30 border-b-2 border-amber-300 dark:border-amber-600 shadow-md">
            <div class="px-4 py-2.5 max-w-5xl mx-auto">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <x-icon name="o-clock" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                        <span class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">Working Time (UTC)</span>
                    </div>
                    <div class="flex items-center gap-2 flex-1 min-w-0">
                        <x-flatpickr
                            wire:model.live="workingTime"
                            min="{{ $this->event->start_time->subMinutes(5)->format('Y-m-d H:i') }}"
                            max="{{ $this->event->end_time->addMinutes(5)->format('Y-m-d H:i') }}"
                            class="input-sm font-mono border-amber-300 focus:border-amber-500 text-base-content bg-base-100"
                        />
                    </div>
                    <p class="text-xs text-amber-600/80 dark:text-amber-400/70 hidden sm:block">
                        Adjust as you move through your paper log pages
                    </p>
                </div>
            </div>
        </div>

        {{-- Station Header --}}
        <div class="bg-base-100 border-b border-base-300 shadow-sm">
            <div class="px-4 py-2.5 max-w-5xl mx-auto flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <x-icon name="o-signal" class="w-4 h-4 text-base-content/50 flex-shrink-0" />
                        <span class="font-bold text-lg truncate">{{ $station->name }}</span>
                        @if($station->is_gota)
                            <x-badge value="GOTA" class="badge-warning badge-sm" />
                        @endif
                    </div>
                    @if($station->primaryRadio)
                        <p class="text-xs text-base-content/50 pl-6 font-mono">
                            {{ $station->primaryRadio->make }} {{ $station->primaryRadio->model }}
                        </p>
                    @endif
                </div>
                <div class="flex-shrink-0">
                    <a href="{{ route('logging.transcribe.select') }}" class="btn btn-ghost btn-sm" wire:navigate>
                        <x-icon name="o-arrow-left" class="w-4 h-4" />
                        <span class="hidden sm:inline">Change Station</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="px-4 py-4 max-w-5xl mx-auto space-y-4">

            {{-- Contact Form Card --}}
            <x-card title="Log Contact" class="shadow-sm">
                <div class="space-y-4">
                    {{-- 4-column grid on desktop --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        {{-- Band --}}
                        <div>
                            <label for="transcribe-band" class="label label-text text-xs font-semibold uppercase tracking-wider mb-1">Band <span class="text-error">*</span></label>
                            <select id="transcribe-band" wire:model.live="selectedBandId" class="select select-bordered select-sm w-full">
                                <option value="">— Band —</option>
                                @foreach($this->bands as $band)
                                    <option value="{{ $band->id }}">{{ $band->name }}</option>
                                @endforeach
                            </select>
                            @error('selectedBandId')
                                <p class="text-error text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Mode --}}
                        <div>
                            <label for="transcribe-mode" class="label label-text text-xs font-semibold uppercase tracking-wider mb-1">Mode <span class="text-error">*</span></label>
                            <select id="transcribe-mode" wire:model.live="selectedModeId" class="select select-bordered select-sm w-full">
                                <option value="">— Mode —</option>
                                @foreach($this->modes as $mode)
                                    <option value="{{ $mode->id }}">{{ $mode->name }}</option>
                                @endforeach
                            </select>
                            @error('selectedModeId')
                                <p class="text-error text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Power --}}
                        <div>
                            <label for="transcribe-power" class="label label-text text-xs font-semibold uppercase tracking-wider mb-1">Power (W)</label>
                            <input
                                id="transcribe-power"
                                type="number"
                                wire:model="powerWatts"
                                min="1"
                                max="1500"
                                class="input input-bordered input-sm w-full"
                                placeholder="100"
                            />
                            @error('powerWatts')
                                <p class="text-error text-xs mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Contact Time --}}
                        <div class="col-span-2">
                            <x-flatpickr
                                label="Contact Time (UTC)"
                                wire:model.live="contactTime"
                                min="{{ $this->event->start_time->subMinutes(5)->format('Y-m-d H:i') }}"
                                max="{{ $this->event->end_time->addMinutes(5)->format('Y-m-d H:i') }}"
                                class="input-sm font-mono"
                            />
                        </div>
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
                                    placeholder="W1AW 1B CT"
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

                        {{-- Keyboard shortcuts --}}
                        <div class="flex flex-wrap gap-x-4 gap-y-1 justify-center text-xs text-base-content/40">
                            <span><kbd class="kbd kbd-xs">Enter</kbd> Log contact</span>
                            <span><kbd class="kbd kbd-xs">Esc</kbd> Clear input</span>
                            <span><kbd class="kbd kbd-xs">&uarr;</kbd><kbd class="kbd kbd-xs">&darr;</kbd> Navigate suggestions</span>
                            <span><kbd class="kbd kbd-xs">Tab</kbd> Accept suggestion</span>
                        </div>
                    </div>
                </div>
            </x-card>

            {{-- Recently Transcribed Contacts --}}
            @if($this->recentContacts->isNotEmpty())
                <x-card title="Recently Transcribed" subtitle="This station only">
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Time (UTC)</th>
                                    <th>Callsign</th>
                                    <th>Band</th>
                                    <th>Mode</th>
                                    <th>Section</th>
                                    <th>Pts</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->recentContacts as $contact)
                                    <tr wire:key="contact-{{ $contact->id }}" @class(['opacity-40' => $contact->is_duplicate])>
                                        <td class="font-mono text-sm">{{ $contact->qso_time->format('H:i') }}</td>
                                        <td class="font-bold font-mono uppercase">{{ $contact->callsign }}</td>
                                        <td class="font-mono text-sm">{{ $contact->band->name ?? '—' }}</td>
                                        <td class="text-sm">{{ $contact->mode->name ?? '—' }}</td>
                                        <td class="text-sm">{{ $contact->section->code ?? '—' }}</td>
                                        <td class="font-mono text-sm">
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
                </x-card>
            @endif
        </div>
    @endif
</div>
