<div
    x-data="{ newContactFlash: false, latestCallsign: '' }"
    @qso-logged.window="newContactFlash = true; latestCallsign = $event.detail.callsign; setTimeout(() => newContactFlash = false, 1500)"
>
    @if ($tvMode)
        {{-- TV Mode: Large display optimized for 10+ foot viewing --}}
        <div
            class="bg-[--tv-surface] rounded-2xl p-8 border border-[--tv-border] transition-all duration-300"
            :class="newContactFlash ? 'ring-4 ring-[--tv-status-excellent] ring-opacity-50' : ''"
        >
            <div class="flex justify-between items-center mb-6">
                <div class="text-3xl font-semibold text-[--tv-text-muted] uppercase tracking-wider">
                    Recent Contacts
                </div>
                <div
                    x-show="newContactFlash"
                    x-transition
                    class="px-4 py-2 bg-[--tv-status-excellent] text-[--tv-bg] rounded-lg text-2xl font-bold"
                >
                    NEW: <span x-text="latestCallsign"></span>
                </div>
            </div>

            @if ($this->recentContacts->isEmpty())
                <div class="text-center py-12 text-2xl text-[--tv-text-muted]">
                    No contacts yet
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($this->recentContacts as $contact)
                        <div class="flex justify-between items-center bg-[--tv-bg] rounded-lg p-4">
                            <div class="flex-1">
                                <div class="text-3xl font-bold text-[--tv-accent-hot]">
                                    {{ $contact->callsign }}
                                </div>
                            </div>
                            <div class="flex gap-6 items-center">
                                <div class="text-2xl text-[--tv-primary]">
                                    {{ $contact->band?->name ?? 'N/A' }}
                                </div>
                                <div class="text-2xl text-[--tv-status-good]">
                                    {{ $contact->mode?->name ?? 'N/A' }}
                                </div>
                                <div class="text-xl text-[--tv-text-muted] font-mono">
                                    {{ $contact->qso_time->format('H:i') }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Normal Mode: Card-based layout --}}
        <div
            class="transition-all duration-300"
            :class="newContactFlash ? 'ring-4 ring-success ring-opacity-50 rounded-lg' : ''"
        >
            <x-mary-card title="Recent Contacts" shadow separator>
                <x-slot:menu>
                    <div class="flex items-center gap-2">
                        <x-mary-icon name="phosphor-users-three" class="w-5 h-5 text-success" />
                        <span
                            x-show="newContactFlash"
                            x-transition
                            class="px-2 py-1 bg-success text-white text-xs font-bold rounded animate-pulse"
                        >
                            NEW
                        </span>
                    </div>
                </x-slot:menu>

            @if ($this->recentContacts->isEmpty())
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                    <x-mary-icon name="phosphor-tray" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                    <p>No contacts logged yet</p>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($this->recentContacts as $contact)
                        <div class="flex justify-between items-center p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                            <div class="flex-1 min-w-0">
                                <div class="text-lg font-bold text-primary truncate">
                                    {{ $contact->callsign }}
                                </div>
                                @if ($contact->section)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $contact->section->abbreviation }}
                                    </div>
                                @endif
                            </div>
                            <div class="flex gap-3 items-center text-sm">
                                <span class="px-2 py-1 rounded bg-primary/10 text-primary font-medium">
                                    {{ $contact->band?->name ?? 'N/A' }}
                                </span>
                                <span class="px-2 py-1 rounded bg-success/10 text-success font-medium">
                                    {{ $contact->mode?->name ?? 'N/A' }}
                                </span>
                                <span class="text-gray-500 dark:text-gray-400 font-mono text-xs">
                                    {{ $contact->qso_time->format('H:i:s') }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            </x-mary-card>
        </div>
    @endif
</div>
