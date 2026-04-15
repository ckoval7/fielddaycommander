<?php

namespace App\Services;

use App\Events\WeatherAlertChanged;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    protected const ALLOWED_NWS_EVENTS = [
        'Tornado Warning',
        'Tornado Watch',
        'Severe Thunderstorm Warning',
        'Severe Thunderstorm Watch',
        'Flash Flood Warning',
        'Flash Flood Watch',
        'High Wind Warning',
        'High Wind Advisory',
        'Extreme Wind Warning',
        'Special Weather Statement',
        'Hurricane Warning',
        'Hurricane Watch',
        'Tropical Storm Warning',
        'Tropical Storm Watch',
        'Ice Storm Warning',
    ];

    protected const MANUAL_ALERT_EVENT = 'Local Alert';

    public function __construct(
        protected readonly ActiveEventService $activeEventService,
    ) {}

    public function getActiveEventCoordinates(): ?array
    {
        $config = $this->activeEventService->getActiveOrUpcomingEvent()?->eventConfiguration;

        if (! $config || ! $config->has_location || ! $config->state) {
            return null;
        }

        return [
            'lat' => (float) $config->latitude,
            'lon' => (float) $config->longitude,
            'state' => $config->state,
        ];
    }

    public function fetchForecast(float $lat, float $lon): void
    {
        if (! $this->isOpenMeteoEnabled()) {
            return;
        }

        try {
            $units = Setting::get('weather.units', 'imperial');

            $response = Http::get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => $lat,
                'longitude' => $lon,
                'current' => 'temperature_2m,wind_speed_10m,wind_gusts_10m,precipitation,weather_code',
                'hourly' => 'temperature_2m,precipitation_probability,rain,wind_speed_10m,wind_gusts_10m,weather_code,cape',
                'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max,wind_gusts_10m_max,weather_code',
                'temperature_unit' => $units === 'metric' ? 'celsius' : 'fahrenheit',
                'wind_speed_unit' => $units === 'metric' ? 'kmh' : 'mph',
                'timezone' => 'auto',
                'forecast_days' => 4,
                'forecast_hours' => 12,
            ]);

            if (! $response->successful()) {
                Log::warning('Open-Meteo API error', ['status' => $response->status()]);
                cache()->put('weather.forecast_status', [
                    'last_attempt' => now()->toIso8601String(),
                    'success' => false,
                    'error' => 'HTTP '.$response->status(),
                ], now()->addHours(2));

                return;
            }

            Setting::set('weather.forecast', $response->json());
            Setting::set('weather.last_fetch', now()->toIso8601String());
            cache()->put('weather.forecast_status', [
                'last_attempt' => now()->toIso8601String(),
                'success' => true,
                'error' => null,
            ], now()->addHours(2));
        } catch (\Throwable $e) {
            Log::error('Failed to fetch weather forecast', ['error' => $e->getMessage()]);
            cache()->put('weather.forecast_status', [
                'last_attempt' => now()->toIso8601String(),
                'success' => false,
                'error' => $e->getMessage(),
            ], now()->addHours(2));
        }
    }

    public function checkAlerts(float $lat, float $lon, string $state): void
    {
        if (! $this->isNwsEnabled()) {
            return;
        }

        try {
            $email = Setting::get('contact_email') ?? 'admin@fielddaycommander.org';
            $response = Http::withHeaders([
                'User-Agent' => '('.config('app.name').', '.config('app.url').'; '.$email.')',
                'Accept' => 'application/geo+json',
            ])->get('https://api.weather.gov/alerts/active', [
                'area' => strtoupper($state),
            ]);

            if (! $response->successful()) {
                Log::warning('NWS API error', ['status' => $response->status()]);
                cache()->put('weather.alerts_status', [
                    'last_attempt' => now()->toIso8601String(),
                    'success' => false,
                    'error' => 'HTTP '.$response->status(),
                ], now()->addHours(2));

                return;
            }

            $alerts = collect($response->json('features', []))
                ->filter(fn ($feature) => in_array(
                    $feature['properties']['event'] ?? '',
                    self::ALLOWED_NWS_EVENTS,
                ))
                ->map(fn ($feature) => [
                    'event' => $feature['properties']['event'],
                    'headline' => $feature['properties']['headline'],
                    'description' => $feature['properties']['description'],
                    'severity' => $feature['properties']['severity'],
                    'expires' => $feature['properties']['expires'],
                ])
                ->values()
                ->all();

            $fingerprint = md5(json_encode($alerts));
            $previousFingerprint = Setting::get('weather.alert_fingerprint');

            Setting::set('weather.alerts', $alerts);

            if ($fingerprint !== $previousFingerprint) {
                Setting::set('weather.alert_fingerprint', $fingerprint);
                WeatherAlertChanged::dispatch($alerts, count($alerts) > 0, false);
            }

            cache()->put('weather.alerts_status', [
                'last_attempt' => now()->toIso8601String(),
                'success' => true,
                'error' => null,
            ], now()->addHours(2));
        } catch (\Throwable $e) {
            Log::error('Failed to check NWS alerts', ['error' => $e->getMessage()]);
            cache()->put('weather.alerts_status', [
                'last_attempt' => now()->toIso8601String(),
                'success' => false,
                'error' => $e->getMessage(),
            ], now()->addHours(2));
        }
    }

    public function getDisplayData(): array
    {
        $override = Setting::get('weather.manual_override');

        if ($override !== null) {
            return ['manual' => true, 'data' => $override];
        }

        return ['manual' => false, 'data' => Setting::get('weather.forecast', [])];
    }

    public function setManualOverride(array $data): void
    {
        Setting::set('weather.manual_override', $data);
    }

    public function clearManualOverride(): void
    {
        Setting::set('weather.manual_override', null);
    }

    public function setManualAlert(string $message): void
    {
        $alerts = [[
            'event' => self::MANUAL_ALERT_EVENT,
            'headline' => $message,
            'description' => $message,
            'severity' => 'Severe',
            'expires' => null,
        ]];

        Setting::set('weather.alerts', $alerts);
        Setting::set('weather.alert_fingerprint', md5(json_encode($alerts)));
        WeatherAlertChanged::dispatch($alerts, true, true);
    }

    public function clearManualAlert(): void
    {
        $alerts = [];
        Setting::set('weather.alerts', $alerts);
        Setting::set('weather.alert_fingerprint', md5(json_encode($alerts)));
        WeatherAlertChanged::dispatch($alerts, false, true);
    }

    public function isOpenMeteoEnabled(): bool
    {
        return (bool) Setting::get('weather.openmeteo_enabled', true);
    }

    public function enableOpenMeteo(): void
    {
        Setting::set('weather.openmeteo_enabled', true);
    }

    public function disableOpenMeteo(): void
    {
        Setting::set('weather.openmeteo_enabled', false);
        Setting::set('weather.forecast', null);
        Setting::set('weather.last_fetch', null);
    }

    public function isWeatherPageVisible(): bool
    {
        return $this->isOpenMeteoEnabled() || Setting::get('weather.manual_override') !== null;
    }

    public function isNwsEnabled(): bool
    {
        return (bool) Setting::get('weather.nws_enabled', true);
    }

    public function enableNws(): void
    {
        Setting::set('weather.nws_enabled', true);
    }

    public function disableNws(): void
    {
        Setting::set('weather.nws_enabled', false);

        $alerts = Setting::get('weather.alerts', []);
        $isManual = collect($alerts)->contains(fn ($alert) => ($alert['event'] ?? '') === self::MANUAL_ALERT_EVENT);

        if (! $isManual) {
            Setting::set('weather.alerts', []);
            Setting::set('weather.alert_fingerprint', md5(json_encode([])));
            WeatherAlertChanged::dispatch([], false, false);
        }
    }
}
