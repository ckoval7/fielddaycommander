<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Many components dispatch "notify" rather than "toast". Both must reach MaryUI's
 * toaster, and because MaryUI renders title and description as HTML, the bridge
 * escapes them (they can include event names and other user data).
 */
test('app layout shows both toast and notify events as escaped toasts', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toContain("Livewire.on('toast', showToast)")
        ->toContain("Livewire.on('notify', showToast)")
        ->toContain("title: escapeHtml(data.title || data.message || '')")
        ->toContain("description: escapeHtml(data.description || '')");
});

test('a flashed toast is shown even after a wire:navigate redirect', function () {
    session()->flash('toast', ['title' => 'Saved', 'description' => 'Done', 'type' => 'success']);

    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toContain('window.fdcToastBridgeReady = true')
        ->toContain('if (window.fdcToastBridgeReady)')
        ->toContain('"title":"Saved"');
});
