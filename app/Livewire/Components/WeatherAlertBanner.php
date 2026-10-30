<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Components;

use App\Models\Setting;
use App\Support\BroadcastChannels;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Session;
use Livewire\Component;

class WeatherAlertBanner extends Component
{
    public array $alerts = [];

    /**
     * Keys of the alerts this browser session has dismissed.
     *
     * @var list<string>
     */
    #[Session]
    public array $dismissedAlertKeys = [];

    public function mount(): void
    {
        $this->setAlerts(Setting::get('weather.alerts', []));
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            BroadcastChannels::publicListener(BroadcastChannels::weather(), 'WeatherAlertChanged') => 'handleAlertUpdate',
        ];
    }

    public function handleAlertUpdate(array $data): void
    {
        $this->setAlerts($data['alerts'] ?? []);
    }

    public function dismiss(): void
    {
        $this->dismissedAlertKeys = array_values(array_unique([
            ...$this->dismissedAlertKeys,
            ...array_map($this->alertKey(...), $this->alerts),
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function visibleAlerts(): array
    {
        return array_values(array_filter(
            $this->alerts,
            fn (array $alert): bool => ! in_array($this->alertKey($alert), $this->dismissedAlertKeys, true),
        ));
    }

    public function isVisible(): bool
    {
        return $this->visibleAlerts() !== [];
    }

    /**
     * Stores the alert list and forgets dismissals for alerts that have ended,
     * so a later alert of the same kind shows again.
     *
     * @param  list<array<string, mixed>>  $alerts
     */
    protected function setAlerts(array $alerts): void
    {
        $this->alerts = $alerts;
        $this->dismissedAlertKeys = array_values(array_intersect(
            $this->dismissedAlertKeys,
            array_map($this->alertKey(...), $alerts),
        ));
    }

    /**
     * Identifies an alert across NWS reissues, which rewrite the headline,
     * description and expiry every update. A local alert is keyed by its
     * message, and an NWS alert by its type and whether it covers the site,
     * so an escalation from nearby to on-site shows again.
     *
     * @param  array<string, mixed>  $alert
     */
    protected function alertKey(array $alert): string
    {
        $event = (string) ($alert['event'] ?? '');

        if ($event === 'Local Alert') {
            return 'local|'.($alert['headline'] ?? '');
        }

        return 'nws|'.$event.'|'.($alert['severity_level'] ?? '');
    }

    public function render(): View
    {
        return view('livewire.components.weather-alert-banner');
    }
}
