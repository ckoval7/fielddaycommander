<?php

namespace App\Livewire\Safety;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class ManageSafetyChecklist extends Component
{
    public function render(): View
    {
        return view('livewire.safety.manage-safety-checklist')
            ->layout('layouts.app');
    }
}
