<?php

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
        // Don't show if already dismissed this session
        if ($this->isDismissed) {
            return false;
        }

        // Don't show if developer mode not enabled
        if (! $clockService->isEnabled()) {
            return false;
        }

        // Don't show if no fake time is set
        if ($clockService->getFakeTime() === null) {
            return false;
        }

        return true;
    }

    public function render(): View
    {
        return view('livewire.components.developer-banner');
    }
}
