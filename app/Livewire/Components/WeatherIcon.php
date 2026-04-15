<?php

namespace App\Livewire\Components;

use App\Services\WeatherService;
use App\Support\WmoCode;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class WeatherIcon extends Component
{
    public bool $hasData = false;

    public bool $isManual = false;

    public bool $canManageWeather = false;

    public ?int $weatherCode = null;

    public ?float $temperature = null;

    public ?float $gusts = null;

    public function mount(): void
    {
        $this->canManageWeather = auth()->user()?->can('manage-weather') ?? false;
        $this->loadWeatherData();
    }

    public function loadWeatherData(): void
    {
        $result = app(WeatherService::class)->getDisplayData();
        $this->isManual = $result['manual'];
        $data = $result['data'];

        if ($this->isManual) {
            $this->hasData = ! empty($data);
            $this->temperature = isset($data['temperature']) ? (float) $data['temperature'] : null;
            $this->weatherCode = null;
            $this->gusts = isset($data['wind_speed']) ? (float) $data['wind_speed'] : null;
        } else {
            $current = $data['current'] ?? [];
            $this->hasData = ! empty($current);
            $this->weatherCode = isset($current['weather_code']) ? (int) $current['weather_code'] : null;
            $this->temperature = isset($current['temperature_2m']) ? (float) $current['temperature_2m'] : null;
            $this->gusts = isset($current['wind_gusts_10m']) ? (float) $current['wind_gusts_10m'] : null;
        }
    }

    public function iconName(): string
    {
        if ($this->isManual || $this->weatherCode === null) {
            return 'o-cloud';
        }

        return WmoCode::icon($this->weatherCode);
    }

    public function render(): View
    {
        return view('livewire.components.weather-icon');
    }
}
