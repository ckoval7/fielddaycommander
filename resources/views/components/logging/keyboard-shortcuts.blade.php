{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Keyboard Shortcuts Component

Desktop-only legend for the exchange composer's keyboard shortcuts.

Props:
- $logLabel: string - What Enter does
- $recallNoun: string - What the arrow keys recall, singular
--}}

@props([
    'logLabel' => 'Log or save',
    'recallNoun' => 'QSO',
])

<div class="hidden sm:flex flex-wrap gap-x-4 gap-y-1 justify-center text-xs text-base-content/40">
    <span><kbd class="kbd kbd-xs text-base-content">Enter</kbd> {{ $logLabel }}</span>
    <span><kbd class="kbd kbd-xs text-base-content">Esc</kbd> Clear or cancel</span>
    <span><kbd class="kbd kbd-xs text-base-content">&uarr;</kbd><kbd class="kbd kbd-xs text-base-content">&darr;</kbd> Select suggestion or recall {{ $recallNoun }} (start with empty input)</span>
    <span><kbd class="kbd kbd-xs text-base-content">Del</kbd> Delete recalled {{ $recallNoun }}</span>
    <span><kbd class="kbd kbd-xs text-base-content">Tab</kbd> Accept selected suggestion</span>
</div>
