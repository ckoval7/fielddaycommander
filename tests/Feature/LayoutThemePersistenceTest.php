<?php

/**
 * Regression: Livewire's wire:navigate strips unknown attributes from <html>
 * (see livewire.csp.esm.js → replaceHtmlAttributes). The inline FOUC script
 * sets data-theme on first load only; without a livewire:navigated listener,
 * the attribute is wiped on subsequent SPA navigations and dark mode breaks
 * for any element using DaisyUI semantic colors or Tailwind dark: variants.
 */
test('app layout re-applies theme attribute after wire:navigate', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toContain("document.documentElement.setAttribute('data-theme', theme)")
        ->toContain("document.addEventListener('livewire:navigated', applyTheme)");
});

test('guest layout re-applies theme attribute after wire:navigate', function () {
    $html = view('components.layouts.guest', ['slot' => ''])->render();

    expect($html)
        ->toContain("document.documentElement.setAttribute('data-theme', theme)")
        ->toContain("document.addEventListener('livewire:navigated', applyTheme)");
});
