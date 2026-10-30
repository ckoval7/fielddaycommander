{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div>
<div class="max-sm:-mx-3" wire:ignore.self x-data="contactQueue({{ $operatingSession->id }}, '{{ csrf_token() }}', {
        band_id: {{ $operatingSession->band_id }},
        mode_id: {{ $operatingSession->mode_id }},
        power_watts: {{ $operatingSession->power_watts }},
        server_time_ms: {{ appNow()->getTimestampMs() }},
        get submode() { return document.querySelector('[data-submode-input]')?.value?.trim() || null },
        is_gota: {{ $operatingSession->station->is_gota ? 'true' : 'false' }},
        get gota_operator_first_name() { return document.querySelector('[wire\\:model=gotaOperatorFirstName]')?.value || null },
        get gota_operator_last_name() { return document.querySelector('[wire\\:model=gotaOperatorLastName]')?.value || null },
        get gota_operator_callsign() { return document.querySelector('[wire\\:model=gotaOperatorCallsign]')?.value || null },
        get gota_operator_user_id() { return @this.gotaOperatorUserId || null },
        mode_category: {{ Js::from($operatingSession->mode?->category) }},
        bands: {{ Js::from($this->switchBands->map->only(['id', 'name'])->values()) }},
        restricted_bands: {{ Js::from($this->restrictedSwitchBands) }},
        modes: {{ Js::from($this->switchModes->map->only(['id', 'name', 'category'])->values()) }},
        band_ranges: {{ Js::from((object) $this->bandRanges) }},
        sections: {{ Js::from(\App\Models\Section::where('is_active', true)->pluck('id', 'code')->toArray()) }},
        class_codes: {{ Js::from(\App\Models\OperatingClass::where('event_type_id', $operatingSession->station->eventConfiguration?->event?->event_type_id)->pluck('code')->map(fn ($code) => strtoupper($code))->all()) }}
     })">
    {{-- Sticky Session Info Bar --}}
    <div data-log-sticky-bar class="sticky lg:top-[calc(var(--desktop-header-height,0px)+var(--weather-banner-height,0px))] max-lg:top-[calc(var(--mobile-header-height,0px)+var(--weather-banner-height,0px))] max-lg:z-10 lg:z-30 bg-base-100 border-b border-base-300 shadow-sm">
        <div class="px-4 py-2.5 flex items-center justify-between gap-3">
            {{-- Left: Station name --}}
            <div class="min-w-0">
                <h1 class="font-bold text-lg sm:text-xl truncate block">
                    {{ $operatingSession->station->name ?? 'Station' }}
                    {{-- Narrow screens show power here; the band/mode row below has no room for it. --}}
                    <span class="xl:hidden font-mono font-normal text-sm text-base-content/70">{{ spokenWatts($operatingSession->power_watts) }}</span>
                </h1>
            </div>

            {{-- Center: Band / Mode / Power --}}
            <div class="hidden xl:flex items-center gap-1.5 text-base font-mono tracking-wide">
                <x-logging.band-mode-switch
                    :session="$operatingSession"
                    :bands="$this->switchBands"
                    :modes="$this->switchModes"
                    :shortcuts="true"
                />
                <span class="text-base-content/30" aria-hidden="true">|</span>
                <span class="text-base-content/70">{{ spokenWatts($operatingSession->power_watts) }}</span>
                <span class="text-base-content/30" aria-hidden="true">|</span>
                {{-- Optional sub-mode (e.g. FT8, SSTV); stays set between contacts. Mirrored in the mobile row. --}}
                <input
                    id="log-submode"
                    autocapitalize="characters"
                    autocorrect="off"
                    spellcheck="false"
                    data-submode-input
                    type="text"
                    list="log-submodes"
                    maxlength="20"
                    x-on:input="document.querySelectorAll('[data-submode-input]').forEach(el => el.value = $el.value)"
                    class="input input-xs w-28 pointer-coarse:w-40 uppercase font-mono"
                    placeholder="Sub-mode"
                    aria-label="Sub-mode (optional)"
                />
                <datalist id="log-submodes">
                    @foreach(\App\Services\ModeResolverService::submodeSuggestions($operatingSession->mode?->category) as $suggestion)
                        <option value="{{ $suggestion }}"></option>
                    @endforeach
                </datalist>
            </div>

            {{-- Right: QSO counter + Sync status + End button --}}
            <div class="flex items-center gap-3 flex-shrink-0">
                <div class="flex items-center gap-1">
                    <span class="text-xs uppercase tracking-wider text-base-content/70 max-sm:sr-only">QSOs</span>
                    <span class="font-mono font-bold text-xl sm:text-2xl tabular-nums" x-text="parseInt({{ $operatingSession->qso_count }}) + sessionQueueCount">{{ $operatingSession->qso_count }}</span>
                </div>

                {{-- Sync Status Indicator --}}
                <div class="flex items-center gap-1.5">
                    <span class="inline-block w-2 h-2 rounded-full" :class="statusDotClass" aria-hidden="true"></span>
                    <span x-show="statusLabel" x-text="statusLabel" x-cloak class="text-xs text-base-content/70 max-sm:sr-only"></span>
                    <span x-show="!statusLabel" class="sr-only">All contacts synced</span>
                </div>

                {{-- Failed Contacts Dropdown --}}
                <template x-if="failedCount > 0">
                    <div class="dropdown dropdown-end">
                        <button type="button" class="btn btn-ghost btn-xs text-error gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                            <span x-text="failedCount + ' failed'"></span>
                        </button>
                        <div class="dropdown-content z-50 bg-base-100 border border-base-300 rounded-box shadow-lg p-3 w-72 mt-2">
                            <div class="text-sm font-semibold mb-2">Failed Contacts</div>
                            <template x-for="contact in failedContacts" :key="contact.uuid">
                                <div class="flex items-center justify-between py-1.5 border-b border-base-200 last:border-0">
                                    <div>
                                        <span class="font-mono font-bold text-sm" x-text="contact.callsign"></span>
                                        <span class="text-xs text-error block" x-text="contact.last_error"></span>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="button" @click="retryFailed(contact.uuid)" class="btn btn-ghost btn-xs btn-square" title="Retry" :aria-label="'Retry ' + contact.callsign">
                                            <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                                <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                        <button type="button" @click="discardFailed(contact.uuid)" class="btn btn-ghost btn-xs btn-square text-error" title="Discard" :aria-label="'Discard ' + contact.callsign">
                                            <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
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
                    wire:click="endSession(! hasUnsyncedSessionContacts)"
                    wire:confirm="End this session?"
                />
            </div>
        </div>

        {{-- Second row for band/mode/sub-mode below xl, where one row has no room --}}
        <div class="xl:hidden flex flex-wrap items-center justify-center gap-2 px-3 pb-2 text-sm font-mono text-base-content/60">
            <x-logging.band-mode-switch
                :session="$operatingSession"
                :bands="$this->switchBands"
                :modes="$this->switchModes"
            />
            <input
                data-submode-input
                type="text"
                list="log-submodes"
                maxlength="20"
                x-on:input="document.querySelectorAll('[data-submode-input]').forEach(el => el.value = $el.value)"
                class="input input-xs w-28 pointer-coarse:w-40 uppercase font-mono"
                placeholder="Sub-mode"
                aria-label="Sub-mode (optional)"
                autocapitalize="characters"
                autocorrect="off"
                spellcheck="false"
            />
        </div>

        <div x-show="switchError" x-cloak role="alert" data-band-mode-switch-error class="bg-error text-error-content text-sm font-semibold px-4 py-2 flex items-center justify-between gap-3">
            <span x-text="switchError"></span>
            <button type="button" class="btn btn-ghost btn-xs" @click="switchError = ''" aria-label="Dismiss">
                <x-icon name="phosphor-x" class="w-4 h-4" />
            </button>
        </div>

        {{-- Shown while the browser can't save the queue and contacts are waiting to sync --}}
        <div x-show="storageWarningVisible" x-cloak role="alert" data-queue-storage-warning class="bg-warning text-warning-content text-sm font-semibold px-4 py-2 text-center">
            Contacts waiting to sync aren't saved on this device. Don't close or reload this page until they sync.
        </div>
    </div>

    <div wire:poll.30s="refreshBandModeWarning">
        @if($bandModeWarning)
            <x-alert icon="phosphor-warning" class="alert-warning rounded-none" data-band-mode-warning>
                {{ $bandModeWarning }}
            </x-alert>
        @endif
    </div>

    <div class="sm:px-4 py-2 sm:py-4 max-w-4xl mx-auto flex flex-col gap-2 sm:gap-4">
        {{-- Your Exchange --}}
        <div class="text-center max-sm:px-4">
            <span class="text-base-content/70 text-sm uppercase tracking-wider">Your Exchange</span>
            <div class="text-2xl font-bold font-mono">{{ $this->clubExchange }}</div>
            <div class="text-sm text-base-content/60 italic">{{ $this->phoneticExchange }}</div>
        </div>

        {{-- GOTA Operator Fields --}}
        @if($this->isGotaStation)
            <div class="max-sm:mx-4 bg-info/10 border border-info/30 rounded-lg p-4 space-y-3">
                <div class="flex items-center gap-2">
                    <x-badge value="GOTA" class="badge-info badge-sm" />
                    <span class="text-sm font-semibold">GOTA Operator</span>
                    @if($this->gotaCallsign)
                        <span class="text-xs text-base-content/70 ml-auto">Callsign on air: <span class="font-mono font-bold">{{ $this->gotaCallsign }}</span></span>
                    @endif
                </div>

                {{-- User Lookup --}}
                <div class="relative">
                    <x-input
                        label="Search registered user (optional)"
                        wire:model.live.debounce.300ms="gotaUserSearch"
                        autocomplete="off"
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
                            autocapitalize="words"
                            autocomplete="off"
                            placeholder="First name"
                            class="input-sm"
                        />
                        <x-input
                            label="Last Name"
                            wire:model="gotaOperatorLastName"
                            autocapitalize="words"
                            autocomplete="off"
                            placeholder="Last name"
                            class="input-sm"
                        />
                        <x-input
                            label="Callsign (optional)"
                            wire:model="gotaOperatorCallsign"
                            autocomplete="off"
                            autocapitalize="characters"
                            autocorrect="off"
                            spellcheck="false"
                            placeholder="e.g. W1AW"
                            class="input-sm uppercase"
                        />
                    </div>
                @endif
            </div>
        @endif

        {{-- Log: QSO history (oldest → newest) with the exchange input docked underneath, chat-style --}}
        <x-logging.qso-log
            title="Recent QSOs"
            :subtitle="$this->earlierContacts->isNotEmpty() ? 'Earlier sessions greyed out' : 'Current session'"
        >
            <x-slot:cards>
                {{-- Sessions this one continues through band/mode switches, read-only, a divider per session --}}
                @foreach($this->earlierContacts->reverse()->groupBy('operating_session_id') as $sessionContacts)
                    <x-logging.session-divider :session="$sessionContacts->first()->operatingSession" />
                    @foreach($sessionContacts as $contact)
                        <x-logging.contact-card
                            :contact="$contact"
                            :readonly="true"
                            :details="array_values(array_filter([$contact->exchange_class, $contact->section->code ?? '—', \App\Support\AdvancedContactFields::summary($contact)]))"
                        />
                    @endforeach
                @endforeach
                @if($this->earlierContacts->isNotEmpty())
                    <x-logging.session-divider :session="$operatingSession" :current="true" />
                @endif

                {{-- Server-confirmed --}}
                @foreach($this->recentContacts->reverse() as $contact)
                    <x-logging.contact-card
                        :contact="$contact"
                        :details="array_values(array_filter([$contact->exchange_class, $contact->section->code ?? '—', \App\Support\AdvancedContactFields::summary($contact)]))"
                    />
                @endforeach

                {{-- Pending/failed from the JS queue (newest, so last) --}}
                <template x-for="contact in [...queue].reverse()" :key="contact.uuid">
                    <button
                        type="button"
                        @click="recallByUuid(contact.uuid)"
                        :aria-current="recalledUuid === contact.uuid"
                        :class="{
                            'opacity-60': (contact.status === 'pending' || contact.status === 'syncing') && isPendingIndicatorVisible(contact),
                            'bg-error/5': contact.status === 'failed',
                            'ring-2 ring-primary': recalledUuid === contact.uuid,
                        }"
                        class="w-full flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-base-300 bg-base-100 text-left"
                    >
                        <div class="min-w-0">
                            <div class="font-bold font-mono uppercase text-lg truncate" x-text="contact.callsign"></div>
                            <div class="text-xs text-base-content/60 font-mono" x-text="[contact.exchange_class, contact.section_code || '—', queuedSummary(contact)].filter(Boolean).join(' · ')"></div>
                        </div>
                        <div class="flex flex-col items-end gap-0.5 flex-shrink-0">
                            <span class="font-mono text-xs text-base-content/60" x-text="new Date(contact.qso_time).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC'})"></span>
                            <template x-if="contact.status === 'failed'">
                                <span><span class="badge badge-xs badge-error" :title="contact.last_error">FAIL</span><span class="sr-only" x-text="': ' + contact.last_error"></span></span>
                            </template>
                            <template x-if="contact.status !== 'failed' && contact.status !== 'editing' && isPendingIndicatorVisible(contact)">
                                <span><span class="badge badge-xs badge-info">SYNC</span><span class="sr-only">: waiting to sync</span></span>
                            </template>
                        </div>
                    </button>
                </template>
            </x-slot:cards>

            <x-slot:extraColumns>
                <th scope="col">Details</th>
            </x-slot:extraColumns>

            <x-slot:rows>
                {{-- Sessions this one continues through band/mode switches, read-only, a divider per session --}}
                @foreach($this->earlierContacts->reverse()->groupBy('operating_session_id') as $sessionContacts)
                    <x-logging.session-divider :session="$sessionContacts->first()->operatingSession" :row="true" />
                    @foreach($sessionContacts as $contact)
                        <x-logging.contact-row :contact="$contact" :readonly="true">
                            <td class="font-mono text-xs text-base-content/70">{{ \App\Support\AdvancedContactFields::summary($contact) }}</td>
                        </x-logging.contact-row>
                    @endforeach
                @endforeach
                @if($this->earlierContacts->isNotEmpty())
                    <x-logging.session-divider :session="$operatingSession" :current="true" :row="true" />
                @endif

                {{-- Server-confirmed contacts --}}
                @foreach($this->recentContacts->reverse() as $contact)
                    <x-logging.contact-row
                        :contact="$contact"
                        :number="$this->recentContactNumbers[$contact->id] ?? null"
                        :data-advanced="json_encode($contact->only(\App\Support\AdvancedContactFields::FIELDS))"
                    >
                        <td class="font-mono text-xs text-base-content/70">{{ \App\Support\AdvancedContactFields::summary($contact) }}</td>
                    </x-logging.contact-row>
                @endforeach

                {{-- Pending/Failed contacts from local queue (newest, so last) --}}
                <template x-for="contact in [...queue].reverse()" :key="contact.uuid">
                    <tr
                        @click="recallByUuid(contact.uuid)"
                        class="cursor-pointer hover:bg-base-200"
                        :aria-current="recalledUuid === contact.uuid"
                        :class="{
                        'opacity-60': (contact.status === 'pending' || contact.status === 'syncing') && isPendingIndicatorVisible(contact),
                        'bg-error/5': contact.status === 'failed',
                        '!bg-primary/25': recalledUuid === contact.uuid,
                    }">
                        <td class="font-mono">-</td>
                        <td class="font-mono" x-text="new Date(contact.qso_time).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC'})"></td>
                        <td class="font-bold font-mono uppercase">
                            <button type="button" class="uppercase cursor-pointer" @click.stop="recallByUuid(contact.uuid)" :aria-label="contact.callsign + ', edit contact'" x-text="contact.callsign"></button>
                            <template x-if="contact.status === 'failed'">
                                <span><span class="badge badge-xs badge-error cursor-help ml-1" :title="contact.last_error">FAIL</span><span class="sr-only" x-text="': ' + contact.last_error"></span></span>
                            </template>
                            <template x-if="contact.status !== 'failed' && contact.status !== 'editing' && isPendingIndicatorVisible(contact)">
                                <span><span class="badge badge-xs badge-info ml-1">SYNC</span><span class="sr-only">: waiting to sync</span></span>
                            </template>
                        </td>
                        <td class="font-mono" x-text="contact.exchange_class"></td>
                        <td x-text="contact.section_code || '-'"></td>
                        <td class="font-mono text-xs text-base-content/70" x-text="queuedSummary(contact)"></td>
                    </tr>
                </template>
            </x-slot:rows>

            <x-logging.exchange-composer
                :advanced="true"
                :suggestions="$suggestions"
                :duplicate-warning="$isDuplicate ? $dupeWarning : null"
                :participant-warning="$participantWarning ?: null"
                :error="$parseError ?: null"
            >
                {{-- Server-side errors, from editing a contact that has already synced --}}
                @if($parseError)
                    <x-alert icon="phosphor-warning" class="alert-error">
                        {{ spoken($parseError, spokenAbbreviations($parseError)) }}
                    </x-alert>
                @endif
                <template x-if="parseError">
                    <div class="alert alert-error">
                        <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <span x-text="parseError" aria-hidden="true"></span>
                        <span class="sr-only" x-text="fdcSpokenAbbreviations(parseError)"></span>
                    </div>
                </template>
                <template x-if="offBandSuggestion">
                    <div class="flex flex-wrap items-center gap-2" data-off-band-offer>
                        <button
                            type="button"
                            class="btn btn-warning btn-sm"
                            x-init="$nextTick(() => $el.focus())"
                            @click="switchBandMode(offBandSuggestion.band_id, {{ $operatingSession->mode_id }}, { logOnArrival: true })"
                            @keydown.escape.prevent="offBandSuggestion = null; $refs.exchangeInput.focus()"
                            :disabled="switching"
                        >
                            <x-icon name="phosphor-arrows-left-right" class="w-4 h-4" />
                            <span x-text="'Switch to ' + offBandSuggestion.name"></span>
                        </button>
                        <span class="text-xs text-base-content/60 max-sm:hidden"><kbd class="kbd kbd-xs">Enter</kbd> to switch and log, <kbd class="kbd kbd-xs">Esc</kbd> to go back.</span>
                    </div>
                </template>
            </x-logging.exchange-composer>
        </x-logging.qso-log>

        <x-logging.keyboard-shortcuts :live="true" :example-band="$this->switchExampleBand" />
    </div>
</div>
</div>
