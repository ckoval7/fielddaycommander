<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Settings;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class SiteBranding extends Component
{
    use WithFileUploads;

    public string $site_name = '';

    public ?string $site_tagline = null;

    public ?string $logo_path = null;

    public $new_logo;

    public ?string $footer_text = null;

    public ?string $welcome_message = null;

    /**
     * Runs on every request, so actions stay gated even though the settings
     * page also opens for users who can only manage roles.
     */
    public function boot(): void
    {
        Gate::authorize('manage-settings');
    }

    public function mount(): void
    {
        $this->site_name = Setting::get('site_name', 'Field Day Commander');
        $this->site_tagline = Setting::get('site_tagline');
        $this->logo_path = Setting::get('site_logo_path');
        $this->footer_text = Setting::get('site_footer_text');
        $this->welcome_message = Setting::get('site_welcome_message');
    }

    public function updatedNewLogo(): void
    {
        $this->validate([
            'new_logo' => ['image', 'max:2048', 'mimes:png,jpg,jpeg'],
        ]);
    }

    public function removeLogo(): void
    {
        if ($this->logo_path) {
            Storage::disk('public')->delete($this->logo_path);
            Setting::set('site_logo_path', '');

            AuditLog::log('settings.branding.updated', oldValues: [
                'logo' => $this->logo_path,
            ], newValues: [
                'logo' => null,
            ]);

            $this->logo_path = null;
            $this->dispatch('notify', title: 'Success', description: 'Logo removed.');
        }
    }

    public function save(): void
    {
        $this->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'site_tagline' => ['nullable', 'string', 'max:200'],
            'new_logo' => ['nullable', 'image', 'max:2048', 'mimes:png,jpg,jpeg'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'welcome_message' => ['nullable', 'string', 'max:2000'],
        ]);

        Setting::set('site_name', $this->site_name);

        Setting::set('site_tagline', $this->site_tagline ?? '');

        if ($this->new_logo) {
            // Delete old logo
            if ($this->logo_path) {
                Storage::disk('public')->delete($this->logo_path);
            }

            $extension = $this->new_logo->guessExtension() ?: 'png';
            $path = $this->new_logo->storeAs('branding', 'logo-'.time().'.'.$extension, 'public');

            Setting::set('site_logo_path', $path);
            $this->logo_path = $path;
            $this->new_logo = null;
        }

        Setting::set('site_footer_text', $this->footer_text ?? '');
        Setting::set('site_welcome_message', $this->welcome_message ?? '');

        Setting::clearCache();

        AuditLog::log('settings.branding.updated', newValues: [
            'site_name' => $this->site_name,
            'site_tagline' => $this->site_tagline,
            'has_logo' => ! empty($this->logo_path),
        ]);

        $this->dispatch('notify', title: 'Success', description: 'Site branding saved.');
    }

    public function render()
    {
        return view('livewire.settings.site-branding');
    }

    /**
     * Get friendly field names for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'new_logo' => 'logo',
        ];
    }
}
