<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Components;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class SystemAccountBanner extends Component
{
    public bool $isSystemUser = false;

    public function mount(): void
    {
        $this->isSystemUser = auth()->user()?->isSystemUser() ?? false;
    }

    public function render(): View
    {
        return view('livewire.components.system-account-banner');
    }
}
