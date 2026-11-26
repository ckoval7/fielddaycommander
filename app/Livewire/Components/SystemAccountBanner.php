<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Components;

use App\Support\HostedMode;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SystemAccountBanner extends Component
{
    public bool $isSystemUser = false;

    /**
     * Whether SYSTEM was opened by the hosting service's support staff,
     * which changes the banner from "make a personal account" to a notice
     * that the session is a support visit.
     */
    public bool $isSupportSession = false;

    public function mount(): void
    {
        $this->isSystemUser = auth()->user()?->isSystemUser() ?? false;
        $this->isSupportSession = $this->isSystemUser && HostedMode::hidesSystemAccount();
    }

    public function render(): View
    {
        return view('livewire.components.system-account-banner');
    }
}
