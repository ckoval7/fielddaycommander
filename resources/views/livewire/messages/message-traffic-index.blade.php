<div class="space-y-6">
    {{-- Header --}}
    <x-header
        title="Message Traffic"
        subtitle="Track {{ $ics213Enabled ? 'radiograms, ICS-213 messages, and' : 'radiograms and' }} NTS message handling"
        separator
        progress-indicator
    >
        <x-slot:actions>
            <x-button
                label="New Message"
                icon="o-plus"
                class="btn-primary"
                link="{{ route('events.messages.create', $event) }}"
                responsive
            />
            <div class="hidden sm:contents">
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
                    external
                />
            </div>
        </x-slot:actions>
    </x-header>

    {{-- Bonus Summary Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-4">
        {{-- SM/SEC Message --}}
        <x-card class="shadow-sm">
            <div class="flex items-center gap-2 lg:gap-3">
                @if($this->bonusSummary['sm_message'])
                    <x-icon name="o-check-circle" class="w-6 h-6 lg:w-8 lg:h-8 text-success shrink-0" />
                @else
                    <x-icon name="o-x-circle" class="w-6 h-6 lg:w-8 lg:h-8 text-base-content/30 shrink-0" />
                @endif
                <div class="min-w-0">
                    <div class="text-xs text-base-content/60 uppercase tracking-wide truncate">SM/SEC</div>
                    <div class="text-base lg:text-lg font-bold">{{ $this->bonusSummary['sm_points'] }} pts</div>
                </div>
            </div>
        </x-card>

        {{-- Message Handling --}}
        <x-card class="shadow-sm">
            <div class="flex items-center gap-2 lg:gap-3">
                <x-icon name="o-envelope" class="w-6 h-6 lg:w-8 lg:h-8 text-info shrink-0" />
                <div class="min-w-0">
                    <div class="text-xs text-base-content/60 uppercase tracking-wide truncate">Messages</div>
                    <div class="text-base lg:text-lg font-bold">{{ $this->bonusSummary['traffic_count'] }}/10</div>
                    <div class="text-xs text-base-content/60">{{ $this->bonusSummary['traffic_points'] }} pts</div>
                </div>
            </div>
        </x-card>

        {{-- W1AW Bulletin --}}
        <x-card class="shadow-sm">
            <div class="flex items-center gap-2 lg:gap-3">
                @if($this->bonusSummary['w1aw_bulletin'])
                    <x-icon name="o-check-circle" class="w-6 h-6 lg:w-8 lg:h-8 text-success shrink-0" />
                @else
                    <x-icon name="o-x-circle" class="w-6 h-6 lg:w-8 lg:h-8 text-base-content/30 shrink-0" />
                @endif
                <div class="min-w-0">
                    <div class="text-xs text-base-content/60 uppercase tracking-wide truncate">W1AW</div>
                    <div class="text-base lg:text-lg font-bold">{{ $this->bonusSummary['w1aw_points'] }} pts</div>
                </div>
            </div>
        </x-card>

        {{-- Total --}}
        <x-card class="shadow-sm bg-primary/5 border border-primary/20">
            <div class="flex items-center gap-2 lg:gap-3">
                <x-icon name="o-trophy" class="w-6 h-6 lg:w-8 lg:h-8 text-primary shrink-0" />
                <div class="min-w-0">
                    <div class="text-xs text-base-content/60 uppercase tracking-wide">Total</div>
                    <div class="text-xl lg:text-2xl font-bold text-primary">{{ $this->bonusSummary['total'] }} pts</div>
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

    {{-- Messages --}}
    <x-card shadow>
        @if($this->messages->isEmpty())
            <div class="text-center py-10 text-base-content/60">
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
            </div>
        @else
            {{-- Desktop Table View --}}
            <div class="hidden lg:block overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>#</th>
                            @if($ics213Enabled)
                                <th>Format</th>
                            @endif
                            <th>Role</th>
                            <th>Station of Origin</th>
                            <th>Addressee</th>
                            <th>Date</th>
                            <th>Freq</th>
                            <th>Mode</th>
                            <th>Sent / Delivered</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->messages as $message)
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
                            <tr wire:key="message-{{ $message->id }}">
                                <td>
                                    <span class="font-mono font-semibold">{{ $message->message_number }}</span>
                                    @if($message->is_sm_message)
                                        <x-badge value="SM" class="badge-warning badge-sm ml-1" />
                                    @endif
                                </td>
                                @if($ics213Enabled)
                                    <td>
                                        <span class="text-sm">{{ $message->format->value === 'radiogram' ? 'Radiogram' : 'ICS-213' }}</span>
                                    </td>
                                @endif
                                <td>
                                    <x-badge :value="$roleLabel" class="{{ $roleBadge }} badge-sm" />
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
                                <td>
                                    <span class="font-mono text-sm">{{ $message->frequency ?? '' }}</span>
                                </td>
                                <td>
                                    <span class="text-sm">{{ $message->mode_category ?? '' }}</span>
                                </td>
                                <td>
                                    @if($message->sent_at)
                                        <div class="flex items-center gap-1">
                                            <x-icon name="o-check-circle" class="w-4 h-4 text-success" />
                                            <div>
                                                <div class="text-xs">{{ $message->sent_at->format('M j, g:ia') }}</div>
                                                @if($message->sentByUser)
                                                    <button
                                                        class="text-xs text-base-content/60 hover:text-primary hover:underline cursor-pointer"
                                                        wire:click="editSentBy({{ $message->id }})"
                                                        title="Change sender"
                                                    >{{ $message->sentByUser->call_sign ?? $message->sentByUser->name }}</button>
                                                @endif
                                            </div>
                                            <x-button
                                                icon="o-x-mark"
                                                class="btn-ghost btn-xs"
                                                tooltip="Clear {{ $message->role->value === 'received_delivered' ? 'delivered' : 'sent' }} status"
                                                wire:click="unmarkAsSent({{ $message->id }})"
                                            />
                                        </div>
                                    @else
                                        <x-button
                                            label="Mark {{ $message->role->value === 'received_delivered' ? 'Delivered' : 'Sent' }}"
                                            icon="o-paper-airplane"
                                            class="btn-ghost btn-xs"
                                            wire:click="openSentByModal({{ $message->id }})"
                                        />
                                    @endif
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
                                            external
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
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile Card View --}}
            <div class="lg:hidden grid grid-cols-1 gap-3">
                @foreach($this->messages as $message)
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
                    <x-card wire:key="message-mobile-{{ $message->id }}" class="shadow-sm">
                        <div class="flex flex-col gap-2">
                            {{-- Header: Number, Role, Badges --}}
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="font-mono font-bold text-lg">{{ $message->message_number }}</span>
                                    @if($message->is_sm_message)
                                        <x-badge value="SM" class="badge-warning badge-sm" />
                                    @endif
                                    @if($ics213Enabled)
                                        <span class="text-xs text-base-content/60">{{ $message->format->value === 'radiogram' ? 'Radiogram' : 'ICS-213' }}</span>
                                    @endif
                                </div>
                                <x-badge :value="$roleLabel" class="{{ $roleBadge }} badge-sm shrink-0" />
                            </div>

                            {{-- Origin & Addressee --}}
                            <div class="flex items-center gap-2 text-sm flex-wrap">
                                @if($message->station_of_origin)
                                    <div class="flex items-center gap-1">
                                        <span class="text-xs text-base-content/60">From:</span>
                                        <span class="font-mono">{{ $message->station_of_origin }}</span>
                                    </div>
                                    <span class="text-base-content/30">&rarr;</span>
                                @endif
                                <div class="flex items-center gap-1">
                                    <span class="text-xs text-base-content/60">To:</span>
                                    <span class="font-medium">{{ $message->addressee_name }}</span>
                                    @if($message->addressee_city)
                                        <span class="text-xs text-base-content/60">({{ $message->addressee_city }}@if($message->addressee_state), {{ $message->addressee_state }}@endif)</span>
                                    @endif
                                </div>
                            </div>

                            {{-- Frequency & Mode --}}
                            @if($message->frequency || $message->mode_category)
                                <div class="flex items-center gap-2 text-sm">
                                    @if($message->frequency)
                                        <span class="font-mono">{{ $message->frequency }}</span>
                                    @endif
                                    @if($message->mode_category)
                                        <x-badge :value="$message->mode_category" class="badge-sm badge-outline" />
                                    @endif
                                </div>
                            @endif

                            {{-- Date & Sent Status --}}
                            <div class="flex items-center justify-between pt-2 border-t border-base-300 text-sm">
                                <div class="flex items-center gap-3">
                                    <span class="text-xs text-base-content/60">{{ $message->filed_at?->format('M j, Y') ?? '—' }}</span>
                                    @if($message->sent_at)
                                        <div class="flex items-center gap-1">
                                            <x-icon name="o-check-circle" class="w-4 h-4 text-success" />
                                            <span class="text-xs">{{ $message->sent_at->format('M j, g:ia') }}</span>
                                            @if($message->sentByUser)
                                                <button
                                                    class="text-xs text-base-content/60 hover:text-primary hover:underline cursor-pointer"
                                                    wire:click="editSentBy({{ $message->id }})"
                                                >{{ $message->sentByUser->call_sign ?? $message->sentByUser->name }}</button>
                                            @endif
                                        </div>
                                    @else
                                        <x-button
                                            label="Mark {{ $message->role->value === 'received_delivered' ? 'Delivered' : 'Sent' }}"
                                            icon="o-paper-airplane"
                                            class="btn-ghost btn-xs"
                                            wire:click="openSentByModal({{ $message->id }})"
                                        />
                                    @endif
                                </div>
                                <div class="flex items-center gap-1">
                                    @if($message->sent_at)
                                        <x-button
                                            icon="o-x-mark"
                                            class="btn-ghost btn-xs"
                                            tooltip="Clear status"
                                            wire:click="unmarkAsSent({{ $message->id }})"
                                        />
                                    @endif
                                    <x-button
                                        icon="o-pencil"
                                        class="btn-ghost btn-xs"
                                        link="{{ route('events.messages.edit', [$event, $message]) }}"
                                    />
                                    <x-button
                                        icon="o-printer"
                                        class="btn-ghost btn-xs"
                                        link="{{ route('events.messages.print', [$event, $message]) }}"
                                        external
                                    />
                                    @can('delete', $message)
                                        <x-button
                                            icon="o-trash"
                                            class="btn-ghost btn-xs text-error"
                                            wire:click="deleteMessage({{ $message->id }})"
                                            wire:confirm="Are you sure you want to delete message #{{ $message->message_number }}?"
                                        />
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif
    </x-card>

    {{-- Sent By Modal --}}
    <x-modal wire:model="showSentByModal" :title="$isDeliveryModal ? 'Mark as Delivered' : 'Mark as Sent'" class="backdrop-blur">
        <div class="space-y-4">
            <x-select
                :label="$isDeliveryModal ? 'Delivered by' : 'Sent by'"
                wire:model="selectedSentByUserId"
                :options="$this->operators"
                option-value="id"
                option-label="name"
                icon="o-user"
                placeholder="Select operator"
            />

            @unless($isDeliveryModal)
                <div class="grid grid-cols-2 gap-4">
                    <x-input
                        label="Frequency"
                        wire:model="sentFrequency"
                        icon="o-signal"
                        placeholder="e.g., 7.228"
                        maxlength="15"
                    />

                    <x-select
                        label="Mode"
                        wire:model="sentModeCategory"
                        :options="[
                            ['id' => '', 'name' => '—'],
                            ['id' => 'CW', 'name' => 'CW'],
                            ['id' => 'Phone', 'name' => 'Phone'],
                            ['id' => 'Digital', 'name' => 'Digital'],
                        ]"
                        option-value="id"
                        option-label="name"
                        icon="o-radio"
                    />
                </div>
            @endunless
        </div>

        <x-slot:actions>
            <x-button
                label="Cancel"
                wire:click="$set('showSentByModal', false)"
            />
            <x-button
                label="Save"
                class="btn-primary"
                wire:click="saveSentBy"
            />
        </x-slot:actions>
    </x-modal>
</div>
