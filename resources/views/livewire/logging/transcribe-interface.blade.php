<div class="max-sm:-mx-5">
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
        <div data-log-sticky-bar class="sticky top-0 max-lg:top-[var(--mobile-header-height,0px)] max-lg:z-10 lg:z-40 bg-amber-50 dark:bg-amber-900/30 border-b-2 border-amber-300 dark:border-amber-600 shadow-md">
            <div class="px-4 py-2.5 max-w-5xl mx-auto">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <x-icon name="phosphor-calendar" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                        <span class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-400">Log Date</span>
                    </div>

                    <input
                        type="date"
                        wire:model.live="workingDate"
                        aria-label="Log date"
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

        <div class="sm:px-4 py-2 sm:py-4 max-w-5xl mx-auto flex flex-col gap-2 sm:gap-4" x-data="transcribeRecall">
            {{-- Band / Mode / Power --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 max-sm:px-4">
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
                <div class="max-sm:mx-4 bg-info/10 border border-info/30 rounded-lg p-4 space-y-3">
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

            @php
                $timeLabel = $timeIsLocal ? $this->timezoneLabel : 'UTC';
                $displayTime = fn ($contact) => $timeIsLocal ? toLocalTime($contact->qso_time) : $contact->qso_time;
            @endphp

            {{-- Log: transcribed QSOs (oldest → newest) with the exchange input docked underneath, chat-style --}}
            <x-logging.qso-log
                title="Recently Transcribed"
                subtitle="This station only"
                :is-empty="$this->recentContacts->isEmpty()"
                example="1423 W1AW 3A CT"
                :time-heading="'Time ('.$timeLabel.')'"
            >
                <x-slot:cards>
                    @foreach($this->recentContacts->reverse() as $contact)
                        <x-logging.contact-card
                            :contact="$contact"
                            :time="$displayTime($contact)"
                            :details="[
                                $contact->exchange_class,
                                $contact->band->name ?? '—',
                                $contact->mode->name ?? '—',
                                $contact->section->code ?? '—',
                                $contact->points.'pt',
                            ]"
                        />
                    @endforeach
                </x-slot:cards>

                <x-slot:extra-columns>
                    <th>Band</th>
                    <th>Mode</th>
                    <th>Pts</th>
                </x-slot:extra-columns>

                <x-slot:rows>
                    @foreach($this->recentContacts->reverse() as $contact)
                        <x-logging.contact-row
                            :contact="$contact"
                            :number="$this->recentContactNumbers[$contact->id] ?? null"
                            :time="$displayTime($contact)"
                            data-recall-value="{{ $displayTime($contact)->format('Hi') }} {{ $contact->callsign }} {{ $contact->exchange_class }} {{ $contact->section->code ?? '' }}"
                        >
                            <td class="font-mono">{{ $contact->band->name ?? '—' }}</td>
                            <td>{{ $contact->mode->name ?? '—' }}</td>
                            <td class="font-mono">
                                @if($contact->is_duplicate)
                                    <x-badge value="DUPE" class="badge-xs badge-warning" />
                                @else
                                    {{ $contact->points }}
                                @endif
                            </td>
                        </x-logging.contact-row>
                    @endforeach
                </x-slot:rows>

                <x-logging.exchange-composer
                    placeholder="1423 W1AW 3A CT"
                    :suggestions="$suggestions"
                    :duplicate-warning="$isDuplicate ? $dupeWarning : null"
                >
                    @if($parseError)
                        <x-alert icon="phosphor-warning" class="alert-error">
                            {{ $parseError }}
                        </x-alert>
                    @endif

                    <div class="flex flex-wrap items-center gap-x-2 text-xs text-base-content/50">
                        <span>Time: <span class="font-mono font-semibold text-base-content/70">{{ $contactTime }}</span> {{ $timeLabel }}</span>
                        <span class="text-base-content/30">&mdash; prepend time to set, e.g. 1423 W1AW 3A CT</span>
                    </div>
                </x-logging.exchange-composer>
            </x-logging.qso-log>

            <x-logging.keyboard-shortcuts />
        </div>
    @endif
</div>
