<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Settings;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Tabbed settings page hosting the system preferences, branding and role manager components.
 */
class SettingsPage extends Component
{
    public string $activeTab = 'system-preferences';

    public function mount(): void
    {
        if (! auth()->user()->can('manage-settings')) {
            $this->activeTab = 'roles';
        }
    }

    public function render(): View
    {
        return view('livewire.settings.settings-page')->layout('layouts.app');
    }
}
