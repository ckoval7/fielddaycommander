<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Regression: Livewire's wire:navigate strips unknown attributes from <html>
 * (see livewire.csp.esm.js → replaceHtmlAttributes). The inline FOUC script
 * sets data-theme on first load only; without a livewire:navigated listener,
 * the attribute is wiped on subsequent SPA navigations and dark mode breaks
 * for any element using DaisyUI semantic colors or Tailwind dark: variants.
 * livewire:navigated fires after the swapped page paints, so the theme is also
 * re-applied in onSwap; otherwise light mode flashes the prefersdark theme.
 */
test('app layout re-applies theme attribute after wire:navigate', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toContain("document.documentElement.setAttribute('data-theme', theme)")
        ->toContain("document.addEventListener('livewire:navigating', (event) => event.detail.onSwap(applyTheme))")
        ->toContain("document.addEventListener('livewire:navigated', applyTheme)");
});

test('guest layout re-applies theme attribute after wire:navigate', function () {
    $html = view('components.layouts.guest', ['slot' => ''])->render();

    expect($html)
        ->toContain("document.documentElement.setAttribute('data-theme', theme)")
        ->toContain("document.addEventListener('livewire:navigating', (event) => event.detail.onSwap(applyTheme))")
        ->toContain("document.addEventListener('livewire:navigated', applyTheme)");
});

test('guest layout uses the app theme switch, not MaryUI theme-toggle', function () {
    $html = view('components.layouts.guest', ['slot' => ''])->render();

    // MaryUI's toggle stores its theme under "mary-theme" and sets data-theme
    // from it on every load, overriding the "theme" key and flashing the page.
    expect($html)
        ->not->toContain('mary-theme')
        ->toContain("localStorage.setItem('theme', theme)");
});

test('app layout content keeps a narrow gutter on mobile', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)->toMatch('/class="drawer-content[^"]*max-sm:px-3/');
});
