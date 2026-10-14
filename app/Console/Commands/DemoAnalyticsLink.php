<?php

namespace App\Console\Commands;

use App\Http\Controllers\DemoAnalyticsController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class DemoAnalyticsLink extends Command
{
    protected $signature = 'demo:analytics-link
        {--hours=24 : How many hours the link is valid}
        {--range=7d : Date range to embed (today, 7d, 30d, 90d, all)}
        {--api : Generate the JSON API URL instead of the dashboard URL}';

    protected $description = 'Generate a time-limited signed URL for the demo analytics dashboard';

    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->info('Demo mode is disabled.');

            return self::SUCCESS;
        }

        $range = $this->option('range');

        if (! array_key_exists((string) $range, DemoAnalyticsController::RANGES)) {
            $this->error("Invalid range \"{$range}\". Must be one of: ".implode(', ', array_keys(DemoAnalyticsController::RANGES)));

            return self::FAILURE;
        }

        $hours = (int) $this->option('hours');
        $routeName = $this->option('api') ? 'demo.analytics.api' : 'demo.analytics.dashboard';

        $url = URL::temporarySignedRoute(
            $routeName,
            now()->addHours($hours),
            ['range' => $range]
        );

        $this->info("Signed URL (expires in {$hours} hours):");
        $this->line($url);

        return self::SUCCESS;
    }
}
