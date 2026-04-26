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
        {{-- Date & Timezone Bar — sticky at top --}}
        <div class="sticky top-0 max-lg:z-10 lg:z-40 bg-amber-50 dark:bg-amber-900/30 border-b-2 border-amber-300 dark:border-amber-600 shadow-md">
            <div class="px-4 py-2.5 max-w-5xl mx-auto">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <x-icon name="phosphor-calendar" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                        <span class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">Log Date</span>
                    </div>

                    <input
                        type="date"
                        wire:model.live="workingDate"
                        min="{{ $this->event->start_time->format('Y-m-d') }}"
                        max="{{ $this->event->end_time->format('Y-m-d') }}"
                        class="input input-bordered input-sm font-mono border-amber-300 focus:border-amber-500 bg-base-100"
                    />

                    {{-- UTC / Local toggle --}}
                    <div class="join">
                        <button
                            type="button"
                            wire:click="$set('timeIsLocal', false)"
                            @class([
                                'join-item btn btn-xs',
                                'btn-active btn-warning' => !$timeIsLocal,
                            ])
                        >UTC</button>
                        <button
                            type="button"
                            wire:click="$set('timeIsLocal', true)"
                            @class([
                                'join-item btn btn-xs',
                                'btn-active btn-warning' => $timeIsLocal,
                            ])
                        >Local{{ $timeIsLocal ? ' ('.$this->timezoneLabel.')' : '' }}</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Station Header --}}
        <div class="bg-base-100 border-b border-base-300 shadow-sm">
            <div class="px-4 py-2.5 max-w-5xl mx-auto flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <x-icon name="phosphor-cell-signal-high" class="w-4 h-4 text-base-content/50 flex-shrink-0" />
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
                        <x-icon name="phosphor-arrow-left" class="w-4 h-4" />
                        <span class="hidden sm:inline">Change Station</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="px-4 py-4 max-w-5xl mx-auto space-y-4" x-data="{
            si: -1,
            recallIndex: -1,
            recalledContactId: null,

            get isRecalling() { return this.recallIndex >= 0 },

            get recallableContacts() {
                const rows = document.querySelectorAll('tr[wire\\:key^=\'contact-\']');
                const contacts = [];
                rows.forEach(row => {
                    if (row.classList.contains('line-through')) return;
                    const wireKey = row.getAttribute('wire:key');
                    const contactId = wireKey ? parseInt(wireKey.replace('contact-', '')) : null;
                    const recallValue = row.getAttribute('data-recall-value');
                    if (contactId && recallValue) {
                        contacts.push({ id: contactId, exchange: recallValue });
                    }
                });
                return contacts;
            },

            recallUp(inputEl) {
                const contacts = this.recallableContacts;
                if (contacts.length === 0) return;
                if (this.recallIndex < contacts.length - 1) this.recallIndex++;
                const contact = contacts[this.recallIndex];
                if (contact) {
                    inputEl.value = contact.exchange;
                    this.recalledContactId = contact.id;
                }
            },

            recallDown(inputEl) {
                if (this.recallIndex <= 0) { this.exitRecall(inputEl); return; }
                this.recallIndex--;
                const contact = this.recallableContacts[this.recallIndex];
                if (contact) {
                    inputEl.value = contact.exchange;
                    this.recalledContactId = contact.id;
                }
            },

            exitRecall(inputEl) {
                this.recallIndex = -1;
                this.recalledContactId = null;
                if (inputEl) {
                    inputEl.value = '';
                    $wire.set('exchangeInput', '');
                    inputEl.focus();
                }
            },

            deleteRecalled(inputEl) {
                if (!this.isRecalling || !this.recalledContactId) return;
                $wire.call('deleteContact', this.recalledContactId);
                this.exitRecall(inputEl);
            },

            saveRecalled(inputEl) {
                if (!this.isRecalling || !this.recalledContactId) return;
                const exchange = inputEl.value.trim();
                if (!exchange) return;
                $wire.call('updateContact', this.recalledContactId, exchange);
                this.exitRecall(inputEl);
            },

            recallByContactId(id) {
                const input = document.getElementById('exchange-input');
                if (!input) return;
                const all = this.recallableContacts;
                const idx = all.findIndex(c => c.id === id);
                if (idx < 0) return;
                this.recallIndex = idx;
                input.value = all[idx].exchange;
                this.recalledContactId = id;
                $wire.set('exchangeInput', all[idx].exchange);
                input.focus();
            },
        }">

            {{-- Contact Form Card --}}
            <x-card title="Log Contact" class="shadow-sm">
                <div class="space-y-4">
                    {{-- Band / Mode / Power --}}
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
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
                    </div>

                    {{-- GOTA Operator Fields --}}
                    @if($this->isGotaStation)
                        <div class="bg-info/10 border border-info/30 rounded-lg p-4 space-y-3">
                            <div class="flex items-center gap-2">
                                <x-badge value="GOTA" class="badge-info badge-sm" />
                                <span class="text-sm font-semibold">GOTA Operator</span>
                                @if($this->gotaCallsign)
                                    <span class="text-xs text-base-content/50 ml-auto">Callsign on air: <span class="font-mono font-bold">{{ $this->gotaCallsign }}</span></span>
                                @endif
                            </div>

                            {{-- User Lookup --}}
                            <div class="relative">
                                <x-input
                                    label="Search registered user (optional)"
                                    wire:model.live.debounce.300ms="gotaUserSearch"
                                    placeholder="Search by name or callsign..."
                                    class="input-sm"
                                />
                                @if(count($this->gotaUserResults) > 0)
                                    <div class="absolute z-50 w-full mt-1 bg-base-100 border border-base-300 rounded-box shadow-lg max-h-36 overflow-y-auto">
                                        @foreach($this->gotaUserResults as $user)
                                            <button
                                                wire:click="selectGotaUser({{ $user['id'] }})"
                                                class="w-full px-3 py-2 text-left hover:bg-base-200 text-sm"
                                                type="button"
                                            >
                                                {{ $user['label'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if($gotaOperatorUserId)
                                <div class="flex items-center gap-2 text-sm">
                                    <x-badge value="Linked" class="badge-xs badge-success" />
                                    <span>{{ $gotaOperatorFirstName }} {{ $gotaOperatorLastName }} ({{ $gotaOperatorCallsign }})</span>
                                    <button wire:click="clearGotaUser" class="btn btn-ghost btn-xs">Change</button>
                                </div>
                            @else
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                    <x-input
                                        label="First Name"
                                        wire:model="gotaOperatorFirstName"
                                        placeholder="First name"
                                        class="input-sm"
                                    />
                                    <x-input
                                        label="Last Name"
                                        wire:model="gotaOperatorLastName"
                                        placeholder="Last name"
                                        class="input-sm"
                                    />
                                    <x-input
                                        label="Callsign (optional)"
                                        wire:model="gotaOperatorCallsign"
                                        placeholder="e.g. W1AW"
                                        class="input-sm uppercase"
                                    />
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Exchange Input (with optional inline time) --}}
                    <div class="space-y-2">
                        <div class="flex items-center gap-2 text-xs text-base-content/50">
                            <span>Time: <span class="font-mono font-semibold text-base-content/70">{{ $contactTime }}</span> {{ $timeIsLocal ? $this->timezoneLabel : 'UTC' }}</span>
                            <span class="text-base-content/30">&mdash; prepend time to set, e.g. 1423 W1AW 3A CT</span>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <div class="relative flex-1">
                                <input
                                    type="text"
                                    id="exchange-input"
                                    wire:model.live.debounce.300ms="exchangeInput"
                                    x-ref="exchangeInput"
                                    @input="si = -1"
                                    @keydown.enter.prevent="
                                        si >= 0 && $wire.suggestions?.length > 0
                                            ? ($wire.selectSuggestion($wire.suggestions[si].exchange), si = -1)
                                            : (isRecalling
                                                ? saveRecalled($refs.exchangeInput)
                                                : $wire.logContact())
                                    "
                                    @keydown.escape.prevent="
                                        si >= 0
                                            ? (si = -1)
                                            : (isRecalling
                                                ? exitRecall($refs.exchangeInput)
                                                : $wire.clearInput())
                                    "
                                    @keydown.arrow-down.prevent="
                                        $wire.suggestions?.length > 0
                                            ? (si = Math.min(si + 1, $wire.suggestions.length - 1))
                                            : (isRecalling ? recallDown($refs.exchangeInput) : null)
                                    "
                                    @keydown.arrow-up.prevent="
                                        $wire.suggestions?.length > 0
                                            ? (si = Math.max(si - 1, -1))
                                            : ($refs.exchangeInput.value.trim() === '' || isRecalling
                                                ? recallUp($refs.exchangeInput)
                                                : null)
                                    "
                                    @keydown.delete="
                                        if (isRecalling) { $event.preventDefault(); deleteRecalled($refs.exchangeInput); }
                                    "
                                    @keydown.tab.prevent="si >= 0 && $wire.suggestions?.length > 0 ? ($wire.selectSuggestion($wire.suggestions[si].exchange), si = -1) : null"
                                    @contact-logged.window="$refs.exchangeInput.focus(); $refs.exchangeInput.select(); si = -1"
                                    @suggestion-selected.window="$nextTick(() => { $refs.exchangeInput.focus(); si = -1 })"
                                    class="input input-bordered input-lg w-full text-2xl font-mono uppercase tracking-wider"
                                    placeholder="1423 W1AW 3A CT"
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
                            <template x-if="!isRecalling">
                                <div class="flex gap-2 sm:contents">
                                    <x-button
                                        label="Log"
                                        icon="phosphor-check"
                                        class="btn-primary btn-lg flex-1 sm:flex-initial"
                                        wire:click="logContact"
                                        spinner="logContact"
                                        tooltip="Enter"
                                        tooltip-position="tooltip-bottom"
                                    />
                                    <x-button
                                        label="Clear"
                                        icon="phosphor-x"
                                        class="btn-ghost btn-lg flex-1 sm:flex-initial"
                                        wire:click="clearInput"
                                        tooltip="Esc"
                                        tooltip-position="tooltip-bottom"
                                    />
                                </div>
                            </template>
                            <template x-if="isRecalling">
                                <div class="flex gap-2 sm:contents">
                                    <button type="button"
                                        class="btn btn-primary btn-lg flex-1 sm:flex-initial"
                                        @click="saveRecalled($refs.exchangeInput)">
                                        <x-icon name="phosphor-check" class="w-5 h-5" /> Save
                                    </button>
                                    <button type="button"
                                        class="btn btn-error btn-lg flex-1 sm:flex-initial"
                                        @click="deleteRecalled($refs.exchangeInput)">
                                        <x-icon name="phosphor-trash" class="w-5 h-5" /> Delete
                                    </button>
                                    <button type="button"
                                        class="btn btn-ghost btn-lg flex-1 sm:flex-initial"
                                        @click="exitRecall($refs.exchangeInput)">
                                        <x-icon name="phosphor-x" class="w-5 h-5" /> Cancel
                                    </button>
                                </div>
                            </template>
                        </div>

                        @if($parseError)
                            <x-alert icon="phosphor-warning" class="alert-error">
                                {{ $parseError }}
                            </x-alert>
                        @endif

                        {{-- Recall Mode Indicator --}}
                        <template x-if="isRecalling">
                            <div class="alert alert-info py-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                                </svg>
                                <span>
                                    Editing recalled QSO <span x-text="recallIndex + 1" class="font-bold"></span>
                                    — change the exchange above, then tap
                                    <span class="font-semibold">Save</span>,
                                    <span class="font-semibold">Delete</span>, or
                                    <span class="font-semibold">Cancel</span>.
                                </span>
                            </div>
                        </template>

                        @if($isDuplicate)
                            <x-alert x-show="!isRecalling" icon="phosphor-warning" class="alert-warning">
                                Duplicate: {{ $dupeWarning }}
                            </x-alert>
                        @endif

                        {{-- Keyboard shortcuts --}}
                        <div class="hidden sm:flex flex-wrap gap-x-4 gap-y-1 justify-center text-xs text-base-content/40">
                            <span><kbd class="kbd kbd-xs text-base-content">Enter</kbd> Log contact</span>
                            <span><kbd class="kbd kbd-xs text-base-content">Esc</kbd> Clear input</span>
                            <span><kbd class="kbd kbd-xs text-base-content">&uarr;</kbd><kbd class="kbd kbd-xs text-base-content">&darr;</kbd> Recall QSOs</span>
                            <span><kbd class="kbd kbd-xs text-base-content">Del</kbd> Delete recalled</span>
                            <span><kbd class="kbd kbd-xs text-base-content">Tab</kbd> Accept suggestion</span>
                        </div>
                    </div>
                </div>
            </x-card>

            {{-- Recently Transcribed Contacts --}}
            @if($this->recentContacts->isNotEmpty())
                <x-card title="Recently Transcribed" subtitle="This station only">
                    {{-- Mobile: card list --}}
                    <div class="sm:hidden space-y-1.5">
                        @foreach($this->recentContacts as $contact)
                            @php
                                $displayTime = $timeIsLocal ? toLocalTime($contact->qso_time) : $contact->qso_time;
                            @endphp
                            @if($contact->trashed())
                                <div wire:key="card-{{ $contact->id }}"
                                    class="w-full flex items-center justify-between gap-3 p-3 rounded-lg border border-base-300 bg-base-100 opacity-40 line-through">
                                    <div class="min-w-0">
                                        <div class="font-bold font-mono uppercase text-lg truncate">
                                            {{ $contact->callsign }}
                                            <button wire:click="restoreContact({{ $contact->id }})" class="btn btn-ghost btn-xs ml-1 no-underline">Undo</button>
                                        </div>
                                        <div class="text-xs text-base-content/60 font-mono">
                                            {{ $contact->exchange_class }} · {{ $contact->band->name ?? '—' }} · {{ $contact->mode->name ?? '—' }} · {{ $contact->section->code ?? '—' }}
                                        </div>
                                    </div>
                                    <span class="font-mono text-xs text-base-content/60 flex-shrink-0">{{ $displayTime->format('H:i') }}</span>
                                </div>
                            @else
                                <button
                                    type="button"
                                    wire:key="card-{{ $contact->id }}"
                                    @click="recallByContactId({{ $contact->id }})"
                                    :class="{ 'ring-2 ring-primary': recalledContactId === {{ $contact->id }} }"
                                    @class([
                                        'w-full flex items-center justify-between gap-3 p-3 rounded-lg border border-base-300 bg-base-100 text-left',
                                        'opacity-50' => $contact->is_duplicate,
                                    ])
                                >
                                    <div class="min-w-0">
                                        <div class="font-bold font-mono uppercase text-lg truncate">
                                            {{ $contact->callsign }}
                                            @if($contact->is_duplicate)
                                                <x-badge value="DUPE" class="badge-xs badge-warning ml-1" />
                                            @endif
                                        </div>
                                        <div class="text-xs text-base-content/60 font-mono">
                                            {{ $contact->exchange_class }} · {{ $contact->band->name ?? '—' }} · {{ $contact->mode->name ?? '—' }} · {{ $contact->section->code ?? '—' }} · {{ $contact->points }}pt
                                        </div>
                                    </div>
                                    <span class="font-mono text-xs text-base-content/60 flex-shrink-0">{{ $displayTime->format('H:i') }}</span>
                                </button>
                            @endif
                        @endforeach
                    </div>

                    {{-- Desktop: existing table --}}
                    <div class="hidden sm:block overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Time ({{ $timeIsLocal ? $this->timezoneLabel : 'UTC' }})</th>
                                    <th>Callsign</th>
                                    <th>Exchange</th>
                                    <th>Band</th>
                                    <th>Mode</th>
                                    <th>Section</th>
                                    <th>Pts</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->recentContacts as $contact)
                                    @php
                                        $displayTime = $timeIsLocal ? toLocalTime($contact->qso_time) : $contact->qso_time;
                                    @endphp
                                    <tr wire:key="contact-{{ $contact->id }}"
                                        data-recall-value="{{ $displayTime->format('Hi') }} {{ $contact->callsign }} {{ $contact->exchange_class }} {{ $contact->section->code ?? '' }}"
                                        @if(! $contact->trashed())
                                            @click="recallByContactId({{ $contact->id }})"
                                        @endif
                                        :class="{
                                            'ring-2 ring-primary': recalledContactId === {{ $contact->id }},
                                        }"
                                        @class([
                                            'opacity-40 line-through' => $contact->trashed(),
                                            'opacity-50' => ! $contact->trashed() && $contact->is_duplicate,
                                            'cursor-pointer hover:bg-base-200' => ! $contact->trashed(),
                                        ])>
                                        <td class="font-mono text-sm">{{ $displayTime->format('H:i') }}</td>
                                        <td class="font-bold font-mono uppercase">
                                            {{ $contact->callsign }}
                                            @if($contact->trashed())
                                                <button
                                                    wire:click="restoreContact({{ $contact->id }})"
                                                    class="btn btn-ghost btn-xs ml-1"
                                                    title="Undo delete"
                                                >
                                                    Undo
                                                </button>
                                            @elseif($contact->is_duplicate)
                                                <x-badge value="DUPE" class="badge-xs badge-warning ml-1" />
                                            @endif
                                        </td>
                                        <td class="font-mono text-sm exchange-cell">{{ $contact->exchange_class }}</td>
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
