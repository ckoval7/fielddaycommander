<div>
<div x-data="contactQueue({{ $operatingSession->id }}, '{{ csrf_token() }}', {
        band_id: {{ $operatingSession->band_id }},
        mode_id: {{ $operatingSession->mode_id }},
        power_watts: {{ $operatingSession->power_watts }},
        is_gota: {{ $operatingSession->station->is_gota ? 'true' : 'false' }},
        get gota_operator_first_name() { return document.querySelector('[wire\\:model=gotaOperatorFirstName]')?.value || null },
        get gota_operator_last_name() { return document.querySelector('[wire\\:model=gotaOperatorLastName]')?.value || null },
        get gota_operator_callsign() { return document.querySelector('[wire\\:model=gotaOperatorCallsign]')?.value || null },
        get gota_operator_user_id() { return @this.gotaOperatorUserId || null },
        sections: {{ Js::from(\App\Models\Section::where('is_active', true)->pluck('id', 'code')->toArray()) }}
     })">
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

            {{-- Right: QSO counter + Sync status + End button --}}
            <div class="flex items-center gap-3 flex-shrink-0">
                <div class="flex items-center gap-1">
                    <span class="text-xs uppercase tracking-wider text-base-content/50 hidden sm:inline">QSOs</span>
                    <span class="font-mono font-bold text-xl sm:text-2xl tabular-nums" x-text="parseInt({{ $operatingSession->qso_count }}) + pendingCount + failedCount">{{ $operatingSession->qso_count }}</span>
                </div>

                {{-- Sync Status Indicator --}}
                <div class="flex items-center gap-1.5">
                    <span class="inline-block w-2 h-2 rounded-full" :class="statusDotClass"></span>
                    <span x-show="statusLabel" x-text="statusLabel" x-cloak class="text-xs text-base-content/60 hidden sm:inline"></span>
                </div>

                {{-- Failed Contacts Dropdown --}}
                <template x-if="failedCount > 0">
                    <div class="dropdown dropdown-end">
                        <button class="btn btn-ghost btn-xs text-error gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            <span x-text="failedCount + ' failed'"></span>
                        </button>
                        <div tabindex="0" class="dropdown-content z-50 bg-base-100 border border-base-300 rounded-box shadow-lg p-3 w-72 mt-2">
                            <div class="text-sm font-semibold mb-2">Failed Contacts</div>
                            <template x-for="contact in failedContacts" :key="contact.uuid">
                                <div class="flex items-center justify-between py-1.5 border-b border-base-200 last:border-0">
                                    <div>
                                        <span class="font-mono font-bold text-sm" x-text="contact.callsign"></span>
                                        <span class="text-xs text-error block" x-text="contact.last_error"></span>
                                    </div>
                                    <div class="flex gap-1">
                                        <button @click="retryFailed(contact.uuid)" class="btn btn-ghost btn-xs" title="Retry">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                        <button @click="discardFailed(contact.uuid)" class="btn btn-ghost btn-xs text-error" title="Discard">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <x-button
                    label="End Session"
                    icon="phosphor-stop"
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
                        @keydown.enter.prevent="
                            si >= 0 && $wire.suggestions?.length > 0
                                ? ($wire.selectSuggestion($wire.suggestions[si].exchange), si = -1)
                                : (isRecalling
                                    ? saveRecalled($refs.exchangeInput)
                                    : logContact($refs.exchangeInput))
                        "
                        @keydown.escape.prevent="
                            si >= 0
                                ? (si = -1)
                                : (isRecalling
                                    ? exitRecall($refs.exchangeInput)
                                    : (($refs.exchangeInput.value = ''), $wire.clearInput()))
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
                    icon="phosphor-check"
                    class="btn-primary btn-lg"
                    @click="logContact($refs.exchangeInput)"
                    tooltip="Enter"
                    tooltip-position="tooltip-bottom"
                />
                <x-button
                    label="Clear"
                    icon="phosphor-x"
                    class="btn-ghost btn-lg"
                    wire:click="clearInput"
                    tooltip="Esc"
                    tooltip-position="tooltip-bottom"
                />
            </div>

            <template x-if="parseError">
                <div class="alert alert-error">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <span x-text="parseError"></span>
                </div>
            </template>

            {{-- Recall Mode Indicator --}}
            <template x-if="isRecalling">
                <div class="alert alert-info py-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                    </svg>
                    <span>
                        Recalled QSO <span x-text="recallIndex + 1" class="font-bold"></span>
                        — <kbd class="kbd kbd-xs text-base-content">Del</kbd> delete
                        · <kbd class="kbd kbd-xs text-base-content">Enter</kbd> save edits
                        · <kbd class="kbd kbd-xs text-base-content">Esc</kbd> cancel
                    </span>
                </div>
            </template>

            @if($isDuplicate)
                <x-alert x-show="!isRecalling" icon="phosphor-warning" class="alert-warning">
                    Duplicate: {{ $dupeWarning }}
                </x-alert>
            @endif

            {{-- Keyboard Shortcuts Help --}}
            <div class="flex flex-wrap gap-x-4 gap-y-1 justify-center text-xs text-base-content/40">
                <span><kbd class="kbd kbd-xs text-base-content">Enter</kbd> Log contact</span>
                <span><kbd class="kbd kbd-xs text-base-content">Esc</kbd> Clear input</span>
                <span><kbd class="kbd kbd-xs text-base-content">&uarr;</kbd><kbd class="kbd kbd-xs text-base-content">&darr;</kbd> Recall QSOs</span>
                <span><kbd class="kbd kbd-xs text-base-content">Del</kbd> Delete recalled</span>
                <span><kbd class="kbd kbd-xs text-base-content">Tab</kbd> Accept suggestion</span>
            </div>
        </div>

        {{-- Recent QSOs --}}
        <x-card title="Recent QSOs" subtitle="This session only">
            {{-- Empty state: no server contacts and no queued contacts --}}
            @if($this->recentContacts->isEmpty())
                <div x-show="queue.length === 0">
                    <div class="text-center py-4 text-base-content/50 space-y-1">
                        <p>No contacts logged yet.</p>
                        <p class="text-xs">Type the other station's exchange above, e.g. <span class="font-mono font-bold">W1AW 3A CT</span></p>
                    </div>
                </div>
            @endif

            <div class="overflow-x-auto" @if($this->recentContacts->isEmpty()) x-show="queue.length > 0" x-cloak @endif>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Time</th>
                            <th>Callsign</th>
                            <th>Exchange</th>
                            <th>Section</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Pending/Failed contacts from local queue --}}
                        <template x-for="contact in queue" :key="contact.uuid">
                            <tr :class="{
                                'opacity-60': contact.status === 'pending' || contact.status === 'syncing',
                                'bg-error/5': contact.status === 'failed'
                            }">
                                <td class="font-mono">-</td>
                                <td class="font-mono" x-text="new Date(contact.qso_time).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', hour12: false})"></td>
                                <td class="font-bold font-mono uppercase">
                                    <span x-text="contact.callsign"></span>
                                    <template x-if="contact.status === 'failed'">
                                        <span class="badge badge-xs badge-error cursor-help ml-1" :title="contact.last_error">FAIL</span>
                                    </template>
                                    <template x-if="contact.status !== 'failed'">
                                        <span class="badge badge-xs badge-info ml-1">SYNC</span>
                                    </template>
                                </td>
                                <td class="font-mono" x-text="contact.exchange_class"></td>
                                <td x-text="contact.section_code || '-'"></td>
                            </tr>
                        </template>

                        {{-- Server-confirmed contacts --}}
                        @foreach($this->recentContacts as $contact)
                            <tr wire:key="contact-{{ $contact->id }}"
                                :class="{
                                    'ring-2 ring-primary': recalledContactId === {{ $contact->id }},
                                }"
                                @class([
                                    'opacity-40 line-through' => $contact->trashed(),
                                    'opacity-50' => ! $contact->trashed() && $contact->is_duplicate,
                                ])>
                                <td class="font-mono">{{ $contact->trashed() ? '-' : '' }}</td>
                                <td class="font-mono">{{ $contact->qso_time->format('H:i') }}</td>
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
                                <td class="font-mono">{{ $contact->exchange_class }}</td>
                                <td>{{ $contact->section->code ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</div>
</div>
