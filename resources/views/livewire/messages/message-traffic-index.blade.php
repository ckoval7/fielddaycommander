<div class="space-y-6">
    {{-- Header --}}
    <x-header
        title="Message Traffic"
        subtitle="Track radiograms and NTS message handling"
        separator
        progress-indicator
    >
        <x-slot:actions>
            <x-button
                label="New Message"
                icon="o-plus"
                class="btn-primary"
                link="{{ route('events.messages.create', $event) }}"
            />
            <x-button
                label="SM/SEC Message"
                icon="o-star"
                class="btn-outline btn-sm"
                link="{{ route('events.messages.create', ['event' => $event, 'template' => 'sm']) }}"
            />
            <x-button
                label="W1AW Bulletin"
                icon="o-radio"
                class="btn-outline btn-sm"
                link="{{ route('events.w1aw-bulletin', $event) }}"
            />
            <x-button
                label="Print All"
                icon="o-printer"
                class="btn-ghost btn-sm"
                link="{{ route('events.messages.print-all', $event) }}"
            />
        </x-slot:actions>
    </x-header>

    {{-- Bonus Summary Cards --}}
    <div class="flex flex-wrap gap-4">
        {{-- SM/SEC Message --}}
        <x-card class="flex-1 min-w-48 shadow-sm">
            <div class="flex items-center gap-3">
                @if($this->bonusSummary['sm_message'])
                    <x-icon name="o-check-circle" class="w-8 h-8 text-success" />
                @else
                    <x-icon name="o-x-circle" class="w-8 h-8 text-base-content/30" />
                @endif
                <div>
                    <div class="text-xs text-base-content/60 uppercase tracking-wide">SM/SEC Message</div>
                    <div class="text-lg font-bold">{{ $this->bonusSummary['sm_points'] }} pts</div>
                </div>
            </div>
        </x-card>

        {{-- Message Handling --}}
        <x-card class="flex-1 min-w-48 shadow-sm">
            <div class="flex items-center gap-3">
                <x-icon name="o-envelope" class="w-8 h-8 text-info" />
                <div>
                    <div class="text-xs text-base-content/60 uppercase tracking-wide">Message Handling</div>
                    <div class="text-lg font-bold">{{ $this->bonusSummary['traffic_count'] }}/10 msgs &mdash; {{ $this->bonusSummary['traffic_points'] }} pts</div>
                </div>
            </div>
        </x-card>

        {{-- W1AW Bulletin --}}
        <x-card class="flex-1 min-w-48 shadow-sm">
            <div class="flex items-center gap-3">
                @if($this->bonusSummary['w1aw_bulletin'])
                    <x-icon name="o-check-circle" class="w-8 h-8 text-success" />
                @else
                    <x-icon name="o-x-circle" class="w-8 h-8 text-base-content/30" />
                @endif
                <div>
                    <div class="text-xs text-base-content/60 uppercase tracking-wide">W1AW Bulletin</div>
                    <div class="text-lg font-bold">{{ $this->bonusSummary['w1aw_points'] }} pts</div>
                </div>
            </div>
        </x-card>

        {{-- Total --}}
        <x-card class="flex-1 min-w-48 shadow-sm bg-primary/5 border border-primary/20">
            <div class="flex items-center gap-3">
                <x-icon name="o-trophy" class="w-8 h-8 text-primary" />
                <div>
                    <div class="text-xs text-base-content/60 uppercase tracking-wide">Total Bonus</div>
                    <div class="text-2xl font-bold text-primary">{{ $this->bonusSummary['total'] }} pts</div>
                </div>
            </div>
        </x-card>
    </div>

    {{-- Role Filter Tabs --}}
    <div class="flex gap-2 flex-wrap">
        <x-button
            label="All"
            class="{{ $roleFilter === null ? 'btn-primary btn-sm' : 'btn-ghost btn-sm' }}"
            wire:click="$set('roleFilter', null)"
        />
        <x-button
            label="Originated"
            class="{{ $roleFilter === 'originated' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm' }}"
            wire:click="$set('roleFilter', 'originated')"
        />
        <x-button
            label="Relayed"
            class="{{ $roleFilter === 'relayed' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm' }}"
            wire:click="$set('roleFilter', 'relayed')"
        />
        <x-button
            label="Received & Delivered"
            class="{{ $roleFilter === 'received_delivered' ? 'btn-primary btn-sm' : 'btn-ghost btn-sm' }}"
            wire:click="$set('roleFilter', 'received_delivered')"
        />
    </div>

    {{-- Messages Table --}}
    <x-card shadow>
        <div class="overflow-x-auto">
            <table class="table table-zebra">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Format</th>
                        <th>Role</th>
                        <th>Station of Origin</th>
                        <th>Addressee</th>
                        <th>Date</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->messages as $message)
                        <tr wire:key="message-{{ $message->id }}">
                            <td>
                                <span class="font-mono font-semibold">{{ $message->message_number }}</span>
                                @if($message->is_sm_message)
                                    <x-badge value="SM" class="badge-warning badge-sm ml-1" />
                                @endif
                            </td>
                            <td>
                                <span class="text-sm">{{ $message->format->value === 'radiogram' ? 'Radiogram' : 'ICS-213' }}</span>
                            </td>
                            <td>
                                @php
                                    $roleLabel = match($message->role->value) {
                                        'originated' => 'Originated',
                                        'relayed' => 'Relayed',
                                        'received_delivered' => 'Rcvd & Delivered',
                                        default => $message->role->value,
                                    };
                                    $roleBadge = match($message->role->value) {
                                        'originated' => 'badge-info',
                                        'relayed' => 'badge-warning',
                                        'received_delivered' => 'badge-success',
                                        default => 'badge-neutral',
                                    };
                                @endphp
                                <x-badge value="{{ $roleLabel }}" class="{{ $roleBadge }} badge-sm" />
                            </td>
                            <td>
                                <span class="font-mono text-sm">{{ $message->station_of_origin ?? '—' }}</span>
                            </td>
                            <td>
                                <div class="font-medium">{{ $message->addressee_name }}</div>
                                @if($message->addressee_city)
                                    <div class="text-xs text-base-content/60">{{ $message->addressee_city }}@if($message->addressee_state), {{ $message->addressee_state }}@endif</div>
                                @endif
                            </td>
                            <td>
                                <span class="text-sm">{{ $message->filed_at?->format('M j, Y') ?? '—' }}</span>
                            </td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <x-button
                                        icon="o-pencil"
                                        class="btn-ghost btn-xs"
                                        tooltip="Edit"
                                        link="{{ route('events.messages.edit', [$event, $message]) }}"
                                    />
                                    <x-button
                                        icon="o-printer"
                                        class="btn-ghost btn-xs"
                                        tooltip="Print"
                                        link="{{ route('events.messages.print', [$event, $message]) }}"
                                    />
                                    @can('delete', $message)
                                        <x-button
                                            icon="o-trash"
                                            class="btn-ghost btn-xs text-error"
                                            tooltip="Delete"
                                            wire:click="deleteMessage({{ $message->id }})"
                                            wire:confirm="Are you sure you want to delete message #{{ $message->message_number }}?"
                                        />
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-10 text-base-content/60">
                                <div class="flex flex-col items-center gap-2">
                                    <x-icon name="o-envelope" class="w-12 h-12 opacity-30" />
                                    <p class="font-medium">No messages found</p>
                                    @if($roleFilter)
                                        <p class="text-sm">Try removing the role filter or log a new message.</p>
                                    @else
                                        <p class="text-sm">Log your first message to get started.</p>
                                    @endif
                                    <x-button
                                        label="Log First Message"
                                        icon="o-plus"
                                        class="btn-primary btn-sm mt-2"
                                        link="{{ route('events.messages.create', $event) }}"
                                    />
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
