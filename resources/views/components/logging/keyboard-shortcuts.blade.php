{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
{{--
Keyboard Shortcuts Component

Desktop-only legend for the exchange composer's keyboard shortcuts: keys in
a right-aligned column beside a short description, in two columns from md.

Props:
- $live: bool - Add the live logging shortcuts (Advanced Fields, band/mode switch)
- $exampleBand: string - Band shown in the band/mode switch hint, one the station can use
--}}

@props(['live' => false, 'exampleBand' => '40M'])

@php
    /** @var array<int, array{keys: array<int, string>, label: string, typed?: bool}> $shortcuts */
    $shortcuts = [
        ['keys' => ['Enter'], 'label' => 'Log, or save an edit'],
        ['keys' => ['Esc'], 'label' => 'Clear, or cancel an edit'],
        ['keys' => ['↑', '↓'], 'label' => 'Recall QSOs, or browse suggestions'],
        ['keys' => ['Del'], 'label' => 'Delete the recalled QSO'],
        ['keys' => ['Tab'], 'label' => $live ? 'Accept suggestion, or advanced fields' : 'Accept suggestion'],
    ];

    if ($live) {
        array_push(
            $shortcuts,
            ['keys' => ['Alt+A'], 'label' => 'Show or hide advanced fields'],
            ['keys' => ['Alt+B', 'Alt+M'], 'label' => 'Focus band or mode picker'],
            ['keys' => [$exampleBand, 'CW'], 'label' => 'Type band/mode alone, Enter to switch', 'typed' => true],
        );
    }
@endphp

<div class="hidden sm:grid md:grid-cols-2 gap-x-10 gap-y-1.5 w-fit mx-auto text-xs text-base-content/60" data-keyboard-shortcuts>
    @foreach($shortcuts as $shortcut)
        <div class="flex items-center gap-2">
            <span class="w-28 flex-shrink-0 flex justify-end items-center gap-1">
                @foreach($shortcut['keys'] as $key)
                    @if($shortcut['typed'] ?? false)
                        @if(! $loop->first)
                            <span>/</span>
                        @endif
                        <span class="font-mono font-semibold text-base-content">{{ $key }}</span>
                    @else
                        <kbd class="kbd kbd-xs text-base-content">{{ $key }}</kbd>
                    @endif
                @endforeach
            </span>
            <span>{{ $shortcut['label'] }}</span>
        </div>
    @endforeach
</div>
