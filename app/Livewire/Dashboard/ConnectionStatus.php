<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Dashboard;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class ConnectionStatus extends Component
{
    public bool $showBanner = false;

    public bool $showBadge = true;

    public bool $isTvMode = false;

    public function mount(bool $isTvMode = false): void
    {
        $this->isTvMode = $isTvMode;
    }

    public function render(): View
    {
        return view('livewire.dashboard.connection-status');
    }
}
