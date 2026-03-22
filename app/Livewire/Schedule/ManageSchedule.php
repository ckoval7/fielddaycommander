<?php

namespace App\Livewire\Schedule;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class ManageSchedule extends Component
{
    public function render(): View
    {
        return view('livewire.schedule.manage-schedule')->layout('layouts::app');
    }
}
