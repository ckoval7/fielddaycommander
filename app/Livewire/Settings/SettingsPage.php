<?php

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Tabbed settings page hosting the system preferences, branding and role manager components.
 */
class SettingsPage extends Component
{
    public string $activeTab = 'system-preferences';

    public function render(): View
    {
        return view('livewire.settings.settings-page')->layout('layouts.app');
    }
}
