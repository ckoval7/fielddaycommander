{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Keyboard Shortcuts Component

Desktop-only legend for the exchange composer's keyboard shortcuts.

Props:
- $live: bool - Add the live logging shortcuts (Advanced Fields, band/mode switch)
--}}

@props(['live' => false])

<div class="hidden sm:flex flex-wrap gap-x-4 gap-y-1 justify-center text-xs text-base-content/40">
    <span><kbd class="kbd kbd-xs text-base-content">Enter</kbd> Log or save</span>
    <span><kbd class="kbd kbd-xs text-base-content">Esc</kbd> Clear or cancel</span>
    <span><kbd class="kbd kbd-xs text-base-content">&uarr;</kbd><kbd class="kbd kbd-xs text-base-content">&darr;</kbd> Select suggestion or recall QSO (start with empty input)</span>
    <span><kbd class="kbd kbd-xs text-base-content">Del</kbd> Delete recalled QSO</span>
    <span><kbd class="kbd kbd-xs text-base-content">Tab</kbd> Accept selected suggestion{{ $live ? ', or move into the advanced fields' : '' }}</span>
    @if($live)
        <span><kbd class="kbd kbd-xs text-base-content">Alt+A</kbd> Show or hide advanced fields</span>
        <span><kbd class="kbd kbd-xs text-base-content">Alt+B</kbd> <kbd class="kbd kbd-xs text-base-content">Alt+M</kbd> Focus band or mode picker</span>
        <span>Type <span class="font-mono">40M</span>, <span class="font-mono">CW</span>, or <span class="font-mono">20M PH</span> and press <kbd class="kbd kbd-xs text-base-content">Enter</kbd> to switch</span>
    @endif
</div>
