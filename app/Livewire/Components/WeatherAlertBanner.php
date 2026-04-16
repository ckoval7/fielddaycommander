<?php

namespace App\Livewire\Components;

use App\Services\WeatherService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Session;
use Livewire\Component;

class WeatherAlertBanner extends Component
{
    public array $alerts = [];

    #[Session]
    public ?string $dismissedFingerprint = null;

    public function mount(): void
    {
        $this->alerts = app(WeatherService::class)->getAlerts();
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo:weather,WeatherAlertChanged' => 'handleAlertUpdate',
        ];
    }

    public function handleAlertUpdate(array $data): void
    {
        $this->alerts = $data['alerts'] ?? [];
        $this->dismissedFingerprint = null;
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
