{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div
    class="mt-6 space-y-4"
    x-data="{
        copied: null,
        async copy(text, which) {
            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                {{-- The clipboard API needs HTTPS, which a field LAN often lacks --}}
                const field = document.createElement('textarea');
                field.value = text;
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                field.remove();
            }
            this.copied = which;
            setTimeout(() => this.copied = null, 2000);
        },
    }"
>
    <x-card shadow>
        <div class="space-y-3">
            <x-toggle
                label="Accept interest responses"
                wire:model.live="formEnabled"
                :disabled="$this->eventIsOver"
                hint="Open a public form where people can tell you when they'd like to help."
            />

            @if($this->eventIsOver)
                <p class="text-sm text-base-content/70">This event has ended, so the form can't open.</p>
            @elseif($formEnabled)
                <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                    <code class="flex-1 min-w-0 break-all rounded-box bg-base-200 px-3 py-2 text-sm">{{ route('interest.form') }}</code>
                    <x-button
                        icon="phosphor-copy"
                        class="btn-outline btn-sm"
                        x-on:click="copy({{ Js::from(route('interest.form')) }}, 'link')"
                    >
                        <span x-text="copied === 'link' ? 'Copied' : 'Copy link'">Copy link</span>
                    </x-button>
                </div>
                <p class="text-sm text-base-content/70">Share this link in your club newsletter, email list, or website.</p>
            @endif
        </div>
    </x-card>

    @if($this->responses->isEmpty())
        <x-card shadow>
            <div class="text-center py-8 text-base-content/60">
                <x-icon name="phosphor-hand-waving" class="w-12 h-12 mx-auto mb-3 opacity-30" />
                <p class="text-lg font-medium">No responses yet</p>
                <p class="text-sm">Responses appear here when people send the form.</p>
            </div>
        </x-card>
    @else
        <x-card shadow>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="font-semibold">
                        {{ $this->responses->count() }} {{ Str::plural('person', $this->responses->count()) }} interested
                    </p>
                    <div class="flex flex-wrap gap-2 mt-2">
                        @foreach(\App\Enums\InterestAvailability::cases() as $block)
                            <span class="badge badge-ghost">
                                {{ $block->label() }}: {{ $this->availabilityCounts[$block->value] }}
                            </span>
                        @endforeach
                    </div>
                </div>
                <x-button
                    icon="phosphor-envelope-simple"
                    class="btn-outline btn-sm"
                    x-on:click="copy({{ Js::from($this->emailList) }}, 'emails')"
                >
                    <span x-text="copied === 'emails' ? 'Copied' : 'Copy all emails'">Copy all emails</span>
                </x-button>
            </div>
        </x-card>

        <div class="space-y-3">
            @foreach($this->responses as $response)
                <x-card shadow wire:key="interest-{{ $response->id }}">
                    <div class="flex flex-col sm:flex-row sm:items-start gap-3">
                        <div class="flex-1 min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-semibold">{{ $response->name }}</span>
                                @if($response->callsign)
                                    <span class="text-base-content/70">{{ $response->callsign }}</span>
                                @endif
                                @if($response->license_class)
                                    <span class="badge badge-sm badge-outline">{{ $response->license_class->label() }}</span>
                                @endif
                                @if($response->user_id)
                                    <span class="badge badge-sm badge-success badge-soft">
                                        <x-icon name="phosphor-user-check" class="w-3.5 h-3.5" /> Has account
                                    </span>
                                @endif
                            </div>
                            <a href="mailto:{{ $response->email }}" class="link text-sm break-all">{{ $response->email }}</a>
                            <div class="flex flex-wrap gap-1">
                                @foreach($response->availability as $block)
                                    <span class="badge badge-sm">{{ $block->label() }}</span>
                                @endforeach
                            </div>
                            @if($response->notes)
                                <p class="text-sm whitespace-pre-line">{{ $response->notes }}</p>
                            @endif
                            <p class="text-xs text-base-content/60">
                                Sent {{ toLocalTime($response->updated_at)->format('M j, ' . timeFormat()) }}
                            </p>
                        </div>
                        <x-button
                            icon="phosphor-trash"
                            class="btn-ghost btn-sm text-error self-end sm:self-start"
                            aria-label="Delete response from {{ $response->name }}"
                            x-on:click="if (await $confirm({ message: {{ Js::from('Delete the response from '.$response->name.'?') }}, confirmLabel: 'Delete', danger: true })) $wire.deleteResponse({{ $response->id }})"
                        />
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</div>
