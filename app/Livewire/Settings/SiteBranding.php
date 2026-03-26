<?php

namespace App\Livewire\Settings;

use App\Models\AuditLog;
use App\Models\Setting;
use enshrined\svgSanitize\Sanitizer;
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

    public function mount(): void
    {
        $this->site_name = Setting::get('site_name', 'Field Day Log Database');
        $this->site_tagline = Setting::get('site_tagline');
        $this->logo_path = Setting::get('site_logo_path');
        $this->footer_text = Setting::get('site_footer_text');
    }

    public function updatedNewLogo(): void
    {
        $this->validate([
            'new_logo' => ['image', 'max:2048', 'mimes:png,jpg,jpeg,svg'],
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
            $this->dispatch('notify', title: 'Success', description: 'Logo removed successfully.');
        }
    }

    public function save(): void
    {
        $this->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'site_tagline' => ['nullable', 'string', 'max:200'],
            'new_logo' => ['nullable', 'image', 'max:2048', 'mimes:png,jpg,jpeg,svg'],
            'footer_text' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::set('site_name', $this->site_name);

        if ($this->site_tagline) {
            Setting::set('site_tagline', $this->site_tagline);
        }

        if ($this->new_logo) {
            // Delete old logo
            if ($this->logo_path) {
                Storage::disk('public')->delete($this->logo_path);
            }

            // Save new logo (sanitize SVGs to prevent XSS)
            $extension = $this->new_logo->guessExtension() ?: 'png';
            $filename = 'logo-'.time().'.'.$extension;

            if ($extension === 'svg') {
                $sanitizer = new Sanitizer;
                $cleanSvg = $sanitizer->sanitize(file_get_contents($this->new_logo->getRealPath()));

                if (! $cleanSvg) {
                    $this->addError('new_logo', 'The SVG file could not be sanitized and was rejected.');

                    return;
                }

                $path = 'branding/'.$filename;
                Storage::disk('public')->put($path, $cleanSvg);
            } else {
                $path = $this->new_logo->storeAs('branding', $filename, 'public');
            }

            Setting::set('site_logo_path', $path);
            $this->logo_path = $path;
            $this->new_logo = null;
        }

        Setting::set('site_footer_text', $this->footer_text ?? '');

        Setting::clearCache();

        AuditLog::log('settings.branding.updated', newValues: [
            'site_name' => $this->site_name,
            'site_tagline' => $this->site_tagline,
            'has_logo' => ! empty($this->logo_path),
        ]);

        $this->dispatch('notify', title: 'Success', description: 'Branding settings saved successfully.');
    }

    public function render()
    {
        return view('livewire.settings.site-branding');
    }
}
