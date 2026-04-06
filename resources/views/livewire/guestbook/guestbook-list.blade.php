<div wire:poll.30s>
    <x-header title="Recent Visitors" subtitle="Who's been here" class="mb-6" />

    @if($entries->isEmpty())
        <x-card class="shadow-md">
            <div class="text-center py-12">
                <x-icon name="o-user-group" class="w-16 h-16 mx-auto text-base-content/30" />
                <p class="mt-4 text-base-content/70">No visitors have signed the guestbook yet.</p>
                <p class="text-sm text-base-content/50 mt-2">Be the first to sign in!</p>
            </div>
        </x-card>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($entries as $entry)
                <x-card
                    class="shadow-md transition-all {{ $entry->is_verified ? 'border-l-4 border-l-success bg-success/5' : '' }}"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            {{-- Name and Callsign --}}
                            <div class="font-semibold text-base truncate">
                                {{ $entry->first_name }} {{ $entry->last_name }}
                                @if($entry->callsign)
                                    <span class="text-sm text-base-content/70">({{ $entry->callsign }})</span>
                                @endif
                            </div>

                            {{-- Presence Type --}}
                            <div class="flex items-center gap-2 mt-1 text-sm text-base-content/60">
                                @if($entry->presence_type === \App\Models\GuestbookEntry::PRESENCE_TYPE_IN_PERSON)
                                    <span title="In Person">🏠 In Person</span>
                                @else
                                    <span title="Online">🌐 Online</span>
                                @endif
                            </div>

                            {{-- Timestamp --}}
                            <div class="text-xs text-base-content/50 mt-2">
                                {{ $entry->created_at->diffForHumans() }}
                            </div>

                            {{-- Comments (if present) --}}
                            @if($entry->comments)
                                <div
                                    x-data="{ expanded: false }"
                                    @mouseenter="expanded = true"
                                    @mouseleave="expanded = false"
                                    class="mt-2 text-sm text-base-content/70 italic cursor-default"
                                    :class="expanded ? '' : 'line-clamp-2'"
                                >
                                    "{{ $entry->comments }}"
                                </div>
                            @endif
                        </div>

                        {{-- Bonus Eligible Badge --}}
                        @if($entry->is_verified && $entry->is_bonus_eligible)
                            <div class="flex-shrink-0">
                                <div
                                    class="w-8 h-8 rounded-full bg-warning flex items-center justify-center"
                                    title="Bonus Eligible - {{ ucfirst(str_replace('_', ' ', $entry->visitor_category)) }}"
                                >
                                    <span class="text-lg">⭐</span>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Verified Badge --}}
                    @if($entry->is_verified)
                        <div class="mt-3 pt-3 border-t border-base-300">
                            <div class="flex items-center gap-2 text-xs text-success">
                                <x-icon name="o-check-circle" class="w-4 h-4" />
                                <span>Verified by {{ $entry->verifiedBy ? $entry->verifiedBy->first_name . ' ' . $entry->verifiedBy->last_name : 'Staff' }}</span>
                            </div>
                        </div>
                    @endif
                </x-card>
            @endforeach
        </div>

        {{-- Entry Count --}}
        @if($entries->count() >= $limit)
            <div class="text-center mt-6 text-sm text-base-content/60">
                Showing latest {{ $limit }} visitors
            </div>
        @endif
    @endif
</div>
