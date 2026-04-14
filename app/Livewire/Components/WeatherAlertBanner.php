<?php

namespace App\Livewire\Components;

use App\Models\Setting;
use Illuminate\View\View;
use Livewire\Attributes\Session;
use Livewire\Component;

class WeatherAlertBanner extends Component
{
    public array $alerts = [];

    public bool $manual = false;

    #[Session]
    public ?string $dismissedFingerprint = null;

    public function mount(): void
    {
        $this->alerts = Setting::get('weather.alerts', []);
        // Detect if active alerts are manually-triggered (vs NWS) by checking event type
        $this->manual = ! empty($this->alerts) && ($this->alerts[0]['event'] ?? '') === 'Local Alert';
    }

    public function getListeners(): array
    {
        return [
            'echo:weather,WeatherAlertChanged' => 'handleAlertUpdate',
        ];
    }

    public function handleAlertUpdate(array $data): void
    {
        $this->alerts = $data['alerts'] ?? [];
        $this->manual = $data['manual'] ?? false;
        $this->dismissedFingerprint = null; // clear dismiss so banner reappears
    }

    public function dismiss(): void
    {
        $this->dismissedFingerprint = $this->currentFingerprint();
    }

    public function isVisible(): bool
    {
        if (empty($this->alerts)) {
            return false;
        }

        return $this->dismissedFingerprint !== $this->currentFingerprint();
    }

    protected function currentFingerprint(): string
    {
        return md5(json_encode($this->alerts));
    }

    public function render(): View
    {
        return view('livewire.components.weather-alert-banner');
    }
}
