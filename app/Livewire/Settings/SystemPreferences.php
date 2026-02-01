<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use Livewire\Component;

class SystemPreferences extends Component
{
    public string $timezone = 'America/New_York';

    public string $date_format = 'Y-m-d';

    public string $time_format = 'H:i:s';

    public ?string $contact_email = null;

    public ?string $api_key = null;

    public function mount(): void
    {
        $this->timezone = Setting::get('timezone', 'America/New_York');
        $this->date_format = Setting::get('date_format', 'Y-m-d');
        $this->time_format = Setting::get('time_format', 'H:i:s');
        $this->contact_email = Setting::get('contact_email');
        $this->api_key = Setting::get('callsign_api_key');
    }

    public function save(): void
    {
        $this->validate([
            'timezone' => ['required', 'string', 'timezone:all'],
            'date_format' => ['required', 'string', 'in:Y-m-d,m/d/Y,d/m/Y'],
            'time_format' => ['required', 'string', 'in:H:i:s,h:i:s A'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        Setting::set('timezone', $this->timezone);
        Setting::set('date_format', $this->date_format);
        Setting::set('time_format', $this->time_format);
        Setting::set('datetime_format', $this->date_format.' '.$this->time_format);

        if ($this->contact_email) {
            Setting::set('contact_email', $this->contact_email);
        }

        if ($this->api_key) {
            Setting::set('callsign_api_key', $this->api_key);
        }

        $this->dispatch('notify', title: 'Success', description: 'System preferences saved successfully.');
    }

    public function getPreviewProperty(): string
    {
        try {
            return now()->timezone($this->timezone ?: 'UTC')->format($this->date_format.' '.$this->time_format);
        } catch (\Exception $e) {
            return 'Invalid format';
        }
    }

    public function render()
    {
        return view('livewire.settings.system-preferences');
    }
}
