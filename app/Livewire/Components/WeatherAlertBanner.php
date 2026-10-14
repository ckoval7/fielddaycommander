<?php

namespace App\Livewire\Components;

use App\Services\WeatherService;
use App\Support\BroadcastChannels;
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
        $listeners = [
            BroadcastChannels::publicListener(BroadcastChannels::weather(), 'WeatherAlertChanged') => 'handleAlertUpdate',
        ];

        if (BroadcastChannels::sharedWeather() !== BroadcastChannels::weather()) {
            $listeners[BroadcastChannels::publicListener(BroadcastChannels::sharedWeather(), 'WeatherAlertChanged')] = 'handleSharedAlertUpdate';
        }

        return $listeners;
    }

    /**
     * Reload alerts after a shared demo weather feed update.
     *
     * The feed's payload is the same for every visitor, so the visitor's own
     * alerts are re-read to keep a manual alert ahead of the feed.
     */
    public function handleSharedAlertUpdate(): void
    {
        $this->alerts = app(WeatherService::class)->getAlerts();
        $this->dismissedFingerprint = null;
    }

    /**
     * Reload rather than trust the payload: in demo mode a visitor's own
     * broadcast carries only their local alert, not the shared NWS feed.
     */
    public function handleAlertUpdate(): void
    {
        $this->alerts = app(WeatherService::class)->getAlerts();
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
        return hash('xxh128', json_encode($this->alerts));
    }

    public function render(): View
    {
        return view('livewire.components.weather-alert-banner');
    }
}
