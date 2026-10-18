<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Services\WeatherService;
use Illuminate\Console\Command;

class CheckWeatherAlerts extends Command
{
    protected $signature = 'weather:check-alerts';

    protected $description = 'Poll NWS for active storm alerts and broadcast changes';

    public function handle(WeatherService $weatherService): int
    {
        $coords = $weatherService->getActiveEventCoordinates();

        if (! $coords) {
            $this->comment('No active or upcoming event with location coordinates. Skipping.');

            return self::SUCCESS;
        }

        $weatherService->checkAlerts($coords['lat'], $coords['lon']);
        $this->info('Weather alerts checked.');

        return self::SUCCESS;
    }
}
