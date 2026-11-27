<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Components;

use App\Support\SignInLinks;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SystemAccountBanner extends Component
{
    public bool $isSystemUser = false;

    /**
     * Who opened SYSTEM with a one-time sign-in link, which changes the
     * banner from "make a personal account" to a notice naming them.
     */
    public ?string $openedBy = null;

    public function mount(): void
    {
        $this->isSystemUser = auth()->user()?->isSystemUser() ?? false;

        if ($this->isSystemUser) {
            $this->openedBy = session(SignInLinks::SESSION_KEY);
        }
    }

    public function render(): View
    {
        return view('livewire.components.system-account-banner');
    }
}
