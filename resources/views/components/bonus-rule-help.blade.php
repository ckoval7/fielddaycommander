{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
@props(['rule' => null, 'rulebook' => 'ARRL'])

<div x-data="{ expanded: false }">
    <div class="flex items-center gap-1">
        {{ $slot }}
        @if ($rule)
            <button
                type="button"
                x-on:click.stop="expanded = !expanded"
                class="btn btn-ghost btn-xs btn-circle text-info hover:text-info-content hover:bg-info"
                :title="expanded ? 'Hide {{ $rulebook }} rule' : 'Show {{ $rulebook }} rule {{ $rule['section'] }}'"
                :aria-expanded="expanded"
                aria-label="Show {{ $rulebook }} rule {{ $rule['section'] }}"
            >
                <x-icon name="phosphor-question" class="w-5 h-5" />
            </button>
        @endif
    </div>

    @if ($rule)
        <div
            x-show="expanded"
            x-collapse
            x-cloak
            class="mt-2 text-xs text-base-content/70 bg-info/5 border border-info/20 rounded-md px-3 py-2 leading-relaxed"
        >
            <x-icon name="phosphor-lightbulb" class="w-3.5 h-3.5 inline text-info mr-1 -mt-0.5" />
            <span class="font-semibold">{{ $rulebook }} Rule {{ $rule['section'] }}:</span>
            {{ $rule['text'] }}
        </div>
    @endif
</div>
