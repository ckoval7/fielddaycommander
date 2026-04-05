<?php

namespace App\Livewire\Components;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class DemoBanner extends Component
{
    public bool $isVisible = false;

    public ?Carbon $expiresAt = null;

    public function mount(): void
    {
        if (! config('demo.enabled')) {
            return;
        }

        $provisionedAt = Setting::get('demo_provisioned_at');

        if (! $provisionedAt) {
            return;
        }

        $this->expiresAt = Carbon::parse($provisionedAt)->addHours(config('demo.ttl_hours', 24));
        $this->isVisible = true;
    }

    public function render(): View
    {
        return view('livewire.components.demo-banner');
    }
}
