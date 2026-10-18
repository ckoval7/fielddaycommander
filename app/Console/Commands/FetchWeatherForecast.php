<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Services\WeatherService;
use Illuminate\Console\Command;

class FetchWeatherForecast extends Command
{
    protected $signature = 'weather:fetch-forecast';

    protected $description = 'Fetch current and 3-day weather forecast from Open-Meteo';

    public function handle(WeatherService $weatherService): int
    {
        $coords = $weatherService->getActiveEventCoordinates();

        if (! $coords) {
            $this->comment('No active or upcoming event with location coordinates. Skipping.');

            return self::SUCCESS;
        }

        $weatherService->fetchForecast($coords['lat'], $coords['lon']);
        $this->info('Weather forecast updated.');

        return self::SUCCESS;
    }
}
