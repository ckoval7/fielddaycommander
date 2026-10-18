<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Components;

use App\Services\DeveloperClockService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Session;
use Livewire\Component;

class DeveloperBanner extends Component
{
    public bool $isVisible = false;

    public ?Carbon $fakeTime = null;

    public bool $isFrozen = false;

    #[Session]
    public bool $isDismissed = false;

    public function mount(): void
    {
        $clockService = app(DeveloperClockService::class);

        $this->isVisible = $this->shouldShow($clockService);

        if ($this->isVisible) {
            // Show the current effective time (includes flowing time calculation)
            $this->fakeTime = $clockService->now();
            $this->isFrozen = $clockService->isFrozen();
        }
    }

    public function dismiss(): void
    {
        $this->isDismissed = true;
    }

    public function refreshTime(): void
    {
        $clockService = app(DeveloperClockService::class);

        if ($this->isVisible && ! $this->isFrozen) {
            $this->fakeTime = $clockService->now();
        }
    }

    protected function shouldShow(DeveloperClockService $clockService): bool
    {
        return ! $this->isDismissed
            && $clockService->isEnabled()
            && $clockService->getFakeTime() !== null;
    }

    public function render(): View
    {
        return view('livewire.components.developer-banner');
    }
}
