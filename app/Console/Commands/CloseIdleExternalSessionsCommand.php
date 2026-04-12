<?php

namespace App\Console\Commands;

use App\Models\OperatingSession;
use Illuminate\Console\Command;

class CloseIdleExternalSessionsCommand extends Command
{
    protected $signature = 'external-logger:close-idle {--minutes=30 : Idle timeout in minutes}';

    protected $description = 'Close external logger sessions that have been idle beyond the timeout';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $threshold = now()->subMinutes($minutes);

        $closed = OperatingSession::query()
            ->whereNotNull('external_source')
            ->whereNull('end_time')
            ->where(function ($query) use ($threshold) {
                $query->where('last_activity_at', '<', $threshold)
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNull('last_activity_at')
                            ->where('created_at', '<', $threshold);
                    });
            })
            ->get();

        foreach ($closed as $session) {
            $session->update(['end_time' => now()]);
        }

        if ($closed->count() > 0) {
            $this->info("Closed {$closed->count()} idle external session(s).");
        }

        return self::SUCCESS;
    }
}
