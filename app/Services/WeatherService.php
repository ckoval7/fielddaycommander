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
        protected readonly EventContextService $eventContextService,
    ) {}

    public function getActiveEventCoordinates(): ?array
    {
        $config = $this->eventContextService->getContextEvent()?->eventConfiguration;

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
                'current' => 'temperature_2m,wind_speed_10m,wind_gusts_10m,precipitation,weather_code,is_day',
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

    public function checkAlerts(float $lat, float $lon): void
    {
        if (! $this->isNwsEnabled()) {
            return;
        }

        try {
            $lat = round($lat, 4);
            $lon = round($lon, 4);

            $points = $this->fetchNwsPoints($lat, $lon);

            if ($points === null) {
                cache()->put('weather.alerts_status', [
                    'last_attempt' => now()->toIso8601String(),
                    'success' => false,
                    'error' => 'Failed to resolve NWS zone/county for coordinates',
                ], now()->addHours(2));

                return;
            }

            $email = Setting::get('contact_email') ?? 'admin@fielddaycommander.org';
            $response = Http::withHeaders([
                'User-Agent' => '('.config('app.name').', '.config('app.url').'; '.$email.')',
                'Accept' => 'application/geo+json',
            ])->get('https://api.weather.gov/alerts/active', [
                'zone' => $points['county'].','.$points['zone'],
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
                ->map(function ($feature) use ($lat, $lon) {
                    $geometry = $feature['geometry'] ?? null;
                    $severityLevel = ($geometry !== null && $this->pointInPolygon($lat, $lon, $geometry))
                        ? 'red'
                        : 'yellow';

                    return [
                        'event' => $feature['properties']['event'],
                        'headline' => $feature['properties']['headline'],
                        'description' => $feature['properties']['description'],
                        'severity' => $feature['properties']['severity'],
                        'expires' => $feature['properties']['expires'],
                        'severity_level' => $severityLevel,
                    ];
                })
                ->values()
                ->all();

            $fingerprint = md5(json_encode($alerts));
            $previousFingerprint = Setting::get('weather.alert_fingerprint');

            $preserveExistingManualAlert = false;
            if (empty($alerts)) {
                $existingAlerts = Setting::get('weather.alerts', []);
                $preserveExistingManualAlert = collect($existingAlerts)->contains(
                    fn ($alert) => ($alert['event'] ?? '') === self::MANUAL_ALERT_EVENT
                );
            }

            if (! $preserveExistingManualAlert) {
                Setting::set('weather.alerts', $alerts);

                if ($fingerprint !== $previousFingerprint) {
                    Setting::set('weather.alert_fingerprint', $fingerprint);
                    WeatherAlertChanged::dispatch($alerts, count($alerts) > 0, false);
                }
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

        $coords = $this->getActiveEventCoordinates();

        if ($coords !== null) {
            $this->fetchForecast($coords['lat'], $coords['lon']);
        }
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

    /**
     * @return array{zone: string, county: string}|null
     */
    private function fetchNwsPoints(float $lat, float $lon): ?array
    {
        $cacheKey = "weather.nws_points.{$lat},{$lon}";

        $cached = cache()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $points = $this->requestNwsPoints($lat, $lon);

        if ($points !== null) {
            cache()->forever($cacheKey, $points);
        }

        return $points;
    }

    /**
     * @return array{zone: string, county: string}|null
     */
    private function requestNwsPoints(float $lat, float $lon): ?array
    {
        $email = Setting::get('contact_email') ?? 'admin@fielddaycommander.org';
        $response = Http::withHeaders([
            'User-Agent' => '('.config('app.name').', '.config('app.url').'; '.$email.')',
            'Accept' => 'application/geo+json',
        ])->get("https://api.weather.gov/points/{$lat},{$lon}");

        if (! $response->successful()) {
            Log::warning('NWS points API error', ['status' => $response->status(), 'lat' => $lat, 'lon' => $lon]);

            return null;
        }

        $zone = basename($response->json('properties.forecastZone') ?? '');
        $county = basename($response->json('properties.county') ?? '');

        if (! $zone || ! $county) {
            Log::warning('NWS points API returned empty zone or county', ['lat' => $lat, 'lon' => $lon]);

            return null;
        }

        $city = trim((string) $response->json('properties.relativeLocation.properties.city', ''));
        $state = trim((string) $response->json('properties.relativeLocation.properties.state', ''));

        if ($city !== '' && $state !== '') {
            Setting::set('weather.location', ['city' => $city, 'state' => $state]);
        }

        return ['zone' => $zone, 'county' => $county];
    }

    private function pointInPolygon(float $lat, float $lon, array $geometry): bool
    {
        $rings = match ($geometry['type']) {
            'Polygon' => [$geometry['coordinates'][0]],
            'MultiPolygon' => array_map(fn ($polygon) => $polygon[0], $geometry['coordinates']),
            default => [],
        };

        foreach ($rings as $ring) {
            if ($this->pointInRing($lat, $lon, $ring)) {
                return true;
            }
        }

        return false;
    }

    private function pointInRing(float $lat, float $lon, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        $j = $n - 1;

        for ($i = 0; $i < $n; $i++) {
            // GeoJSON coordinates are [longitude, latitude]
            $xi = $ring[$i][0]; // lon of vertex i
            $yi = $ring[$i][1]; // lat of vertex i
            $xj = $ring[$j][0]; // lon of vertex j
            $yj = $ring[$j][1]; // lat of vertex j

            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }

            $j = $i;
        }

        return $inside;
    }
}
