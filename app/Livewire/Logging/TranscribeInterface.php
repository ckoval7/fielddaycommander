<?php

namespace App\Livewire\Logging;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class TranscribeInterface extends Component
{
    public function render(): View
    {
        return view('livewire.logging.transcribe-interface')
            ->layout('layouts.app');
    }
}
