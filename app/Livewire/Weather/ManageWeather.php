<?php

namespace App\Livewire\Weather;

use App\Models\Setting;
use App\Services\WeatherService;
use Illuminate\View\View;
use Livewire\Component;

class ManageWeather extends Component
{
    public ?int $temperature = null;

    public ?int $windSpeed = null;

    public string $windDirection = 'N';

    public ?int $precipitationChance = null;

    public string $notes = '';

    public string $alertMessage = '';

    public bool $overrideActive = false;

    public ?array $currentOverride = null;

    public ?array $currentAlerts = null;

    public ?array $forecastStatus = null;

    public ?array $alertsStatus = null;

    public string $units = 'imperial';

    public function mount(): void
    {
        $this->authorize('manage-weather');
        $this->loadCurrentState();
    }

    public function loadCurrentState(): void
    {
        $this->currentOverride = Setting::get('weather.manual_override');
        $this->overrideActive = $this->currentOverride !== null;
        $this->currentAlerts = Setting::get('weather.alerts', []);
        $this->forecastStatus = cache()->get('weather.forecast_status');
        $this->alertsStatus = cache()->get('weather.alerts_status');
        $this->units = Setting::get('weather.units', 'imperial');
    }

    public function activateOverride(WeatherService $weatherService): void
    {
        $this->authorize('manage-weather');
        $this->validate([
            'temperature' => 'required|integer|between:'.($this->units === 'metric' ? '-50,60' : '-60,140'),
            'windSpeed' => 'required|integer|between:0,'.($this->units === 'metric' ? '300' : '200'),
            'windDirection' => 'required|string|in:N,NE,E,SE,S,SW,W,NW',
            'precipitationChance' => 'required|integer|between:0,100',
            'notes' => 'nullable|string|max:500',
        ]);

        $weatherService->setManualOverride([
            'temperature' => $this->temperature,
            'wind_speed' => $this->windSpeed,
            'wind_direction' => $this->windDirection,
            'precipitation_chance' => $this->precipitationChance,
            'notes' => $this->notes,
            'updated_by' => auth()->user()->call_sign,
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->loadCurrentState();
        $this->dispatch('toast', title: 'Manual override activated', type: 'success');
    }

    public function clearOverride(WeatherService $weatherService): void
    {
        $this->authorize('manage-weather');
        $weatherService->clearManualOverride();
        $this->loadCurrentState();
        $this->dispatch('toast', title: 'Override cleared — using live data', type: 'info');
    }

    public function triggerAlert(WeatherService $weatherService): void
    {
        $this->authorize('manage-weather');
        $this->validateOnly('alertMessage', ['alertMessage' => 'required|string|min:5|max:200']);

        $weatherService->setManualAlert($this->alertMessage);
        $this->alertMessage = '';
        $this->loadCurrentState();
        $this->dispatch('toast', title: 'Alert triggered', type: 'warning');
    }

    public function clearAlert(WeatherService $weatherService): void
    {
        $this->authorize('manage-weather');
        $weatherService->clearManualAlert();
        $this->loadCurrentState();
        $this->dispatch('toast', title: 'Alert cleared', type: 'info');
    }

    public function saveUnits(string $units): void
    {
        $this->authorize('manage-weather');

        if (! in_array($units, ['imperial', 'metric'], true)) {
            $this->dispatch('toast', title: 'Invalid unit system', type: 'error');

            return;
        }

        Setting::set('weather.units', $units);
        $this->units = $units;
        $this->resetValidation(['temperature', 'windSpeed']);
        $this->dispatch('toast', title: 'Unit system updated', type: 'success');
    }

    public function render(): View
    {
        return view('livewire.weather.manage-weather')
            ->layout('layouts.app', ['title' => 'Manage Weather']);
    }
}
