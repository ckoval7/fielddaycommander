<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard\Widgets\Concerns;

/**
 * Decides whether a widget should register Echo listeners at all.
 */
trait ListensForBroadcasts
{
    /**
     * Whether this viewer can usefully subscribe to private broadcast channels.
     *
     * Requires a signed-in user (private channels reject guests), a real
     * broadcaster (the log/null drivers never deliver anything), and a Reverb
     * app key (the client only creates Echo when one is configured). Without
     * these, Livewire would warn "Laravel Echo cannot be found" per widget and
     * the widget relies on its polling alone.
     */
    protected function canListenForBroadcasts(): bool
    {
        return auth()->check()
            && ! in_array(config('broadcasting.default'), [null, 'log', 'null'], true)
            && filled(config('reverb.apps.apps.0.key'));
    }
}
