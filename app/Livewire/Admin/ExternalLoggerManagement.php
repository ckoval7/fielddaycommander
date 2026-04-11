<?php

namespace App\Livewire\Admin;

use App\Models\ExternalLoggerSetting;
use App\Services\EventContextService;
use App\Services\ExternalLoggerManager;
use Illuminate\View\View;
use Livewire\Component;

class ExternalLoggerManagement extends Component
{
    public bool $n1mmEnabled = false;

    public int $n1mmPort = 12060;

    public function mount(): void
    {
        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            return;
        }

        $setting = ExternalLoggerSetting::where('event_configuration_id', $config->id)
            ->where('listener_type', 'n1mm')
            ->first();

        if ($setting) {
            $this->n1mmEnabled = $setting->is_enabled;
            $this->n1mmPort = $setting->port;
        }
    }

    public function toggleN1mm(): void
    {
        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            session()->flash('error', 'No active event configuration.');

            return;
        }

        $manager = app(ExternalLoggerManager::class);

        if ($this->n1mmEnabled) {
            $manager->disable($config->id, 'n1mm');
            $this->n1mmEnabled = false;
        } else {
            $manager->enable($config->id, 'n1mm', $this->n1mmPort);
            $this->n1mmEnabled = true;
        }
    }

    public function updatePort(): void
    {
        $this->validate([
            'n1mmPort' => 'required|integer|min:1024|max:65535',
        ]);

        $config = app(EventContextService::class)->getEventConfiguration();
        if ($config === null) {
            return;
        }

        ExternalLoggerSetting::where('event_configuration_id', $config->id)
            ->where('listener_type', 'n1mm')
            ->update(['port' => $this->n1mmPort]);
    }

    public function render(): View
    {
        $config = app(EventContextService::class)->getEventConfiguration();

        return view('livewire.admin.external-logger-management', [
            'hasActiveEvent' => $config !== null,
        ]);
    }
}
