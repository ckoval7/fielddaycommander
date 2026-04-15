<?php

namespace App\Livewire\Weather;

use App\Models\Setting;
use App\Services\WeatherService;
use App\Support\WmoCode;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class WeatherDashboard extends Component
{
    public array $forecast = [];

    public array $manualOverride = [];

    public array $alerts = [];

    public bool $isManual = false;

    public bool $hasData = false;

    public bool $canManageWeather = false;

    public ?string $lastFetch = null;

    public function mount(): void
    {
        $this->canManageWeather = auth()->user()?->can('manage-weather') ?? false;
        $this->loadData();
    }

    public function loadData(): void
    {
        $result = app(WeatherService::class)->getDisplayData();
        $this->isManual = $result['manual'];

        if ($this->isManual) {
            $this->manualOverride = $result['data'];
            $this->hasData = ! empty($this->manualOverride);
            $this->forecast = [];
        } else {
            $this->forecast = $result['data'];
            $this->hasData = ! empty($this->forecast['current'] ?? []);
            $this->manualOverride = [];
        }

        $this->alerts = Setting::get('weather.alerts', []);
        $this->lastFetch = Setting::get('weather.last_fetch');

        unset($this->hourlyData, $this->dailyData);
    }

    /**
     * @return list<array{time: string|null, temperature: float|null, precip_probability: int|null, wind_speed: float|null, weather_code: int|null, cape: float|null}>
     */
    #[Computed]
    public function hourlyData(): array
    {
        if ($this->isManual || empty($this->forecast['hourly'])) {
            return [];
        }

        $hourly = $this->forecast['hourly'];
        $count = min(12, count($hourly['time'] ?? []));
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                'time' => isset($hourly['time'][$i]) ? Carbon::parse($hourly['time'][$i])->format('g A') : null,
                'temperature' => $hourly['temperature_2m'][$i] ?? null,
                'precip_probability' => $hourly['precipitation_probability'][$i] ?? null,
                'wind_speed' => $hourly['wind_speed_10m'][$i] ?? null,
                'weather_code' => isset($hourly['weather_code'][$i]) ? (int) $hourly['weather_code'][$i] : null,
                'cape' => $hourly['cape'][$i] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{date: string|null, high: float|null, low: float|null, precip_chance: int|null, wind_max: float|null, gusts_max: float|null, weather_code: int|null}>
     */
    #[Computed]
    public function dailyData(): array
    {
        if ($this->isManual || empty($this->forecast['daily'])) {
            return [];
        }

        $daily = $this->forecast['daily'];
        $count = min(3, count($daily['time'] ?? []));
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                'date' => isset($daily['time'][$i]) ? Carbon::parse($daily['time'][$i])->format('D, M j') : null,
                'high' => $daily['temperature_2m_max'][$i] ?? null,
                'low' => $daily['temperature_2m_min'][$i] ?? null,
                'precip_chance' => $daily['precipitation_probability_max'][$i] ?? null,
                'wind_max' => $daily['wind_speed_10m_max'][$i] ?? null,
                'gusts_max' => $daily['wind_gusts_10m_max'][$i] ?? null,
                'weather_code' => isset($daily['weather_code'][$i]) ? (int) $daily['weather_code'][$i] : null,
            ];
        }

        return $result;
    }

    public function iconFor(int $code): string
    {
        return WmoCode::icon($code);
    }

    public function labelFor(int $code): string
    {
        return WmoCode::label($code);
    }

    public function render(): View
    {
        return view('livewire.weather.weather-dashboard')
            ->layout('layouts.app', ['title' => 'Weather']);
    }
}
