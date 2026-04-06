<?php

namespace App\Livewire\Settings;

use App\Models\AuditLog;
use App\Models\Setting;
use Livewire\Component;

class SystemPreferences extends Component
{
    public string $timezone = 'America/New_York';

    public string $date_format = 'Y-m-d';

    public string $time_format = 'H:i:s';

    public ?string $contact_email = null;

    public ?string $api_key = null;

    public int $post_event_grace_period_days = 30;

    public bool $enable_ics213 = false;

    public function mount(): void
    {
        $this->timezone = Setting::get('timezone', 'America/New_York');
        $this->date_format = Setting::get('date_format', 'Y-m-d');
        $savedTimeFormat = Setting::get('time_format', 'H:i:s');
        $this->time_format = match ($savedTimeFormat) {
            'H:i' => 'H:i:s',
            'h:i A' => 'h:i:s A',
            default => $savedTimeFormat,
        };
        $this->contact_email = Setting::get('contact_email');
        $this->api_key = Setting::get('callsign_api_key');
        $this->post_event_grace_period_days = (int) Setting::get('post_event_grace_period_days', 30);
        $this->enable_ics213 = Setting::getBoolean('enable_ics213', false);
    }

    public function save(): void
    {
        $this->validate([
            'timezone' => ['required', 'string', 'timezone:all'],
            'date_format' => ['required', 'string', 'in:Y-m-d,m/d/Y,d/m/Y'],
            'time_format' => ['required', 'string', 'in:H:i:s,h:i:s A'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'post_event_grace_period_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $oldValues = [
            'timezone' => Setting::get('timezone'),
            'date_format' => Setting::get('date_format'),
            'time_format' => Setting::get('time_format'),
            'post_event_grace_period_days' => Setting::get('post_event_grace_period_days'),
        ];

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

        Setting::set('post_event_grace_period_days', $this->post_event_grace_period_days);
        Setting::set('enable_ics213', $this->enable_ics213 ? '1' : '0');

        AuditLog::log('settings.updated', oldValues: $oldValues, newValues: [
            'timezone' => $this->timezone,
            'date_format' => $this->date_format,
            'time_format' => $this->time_format,
            'post_event_grace_period_days' => $this->post_event_grace_period_days,
        ]);

        $this->dispatch('notify', title: 'Success', description: 'System preferences saved successfully.');
    }

    public function getDateFormatsProperty(): array
    {
        return [
            ['id' => 'Y-m-d', 'name' => 'YYYY-MM-DD (ISO 8601)'],
            ['id' => 'm/d/Y', 'name' => 'MM/DD/YYYY (US Format)'],
            ['id' => 'd/m/Y', 'name' => 'DD/MM/YYYY (EU Format)'],
        ];
    }

    public function getTimeFormatsProperty(): array
    {
        return [
            ['id' => 'H:i:s', 'name' => '24-hour (HH:MM:SS)'],
            ['id' => 'h:i:s A', 'name' => '12-hour (HH:MM:SS AM/PM)'],
        ];
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
