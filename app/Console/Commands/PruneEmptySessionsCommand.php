<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Services\EmptySessionPruner;
use Illuminate\Console\Command;

/**
 * Removes logging sessions that ended with no contacts, for the paths that
 * end a session without hearing from its browser (a takeover, a station
 * captain's End Sessions, marking a station unavailable, the event closing).
 * The wait gives a logging tab that is still open, maybe offline, time to
 * sync contacts it queued before the session ended.
 */
class PruneEmptySessionsCommand extends Command
{
    /** Minutes a session stays ended before it can be removed. */
    public const DEFAULT_GRACE_MINUTES = 360;

    protected $signature = 'sessions:prune-empty {--minutes='.self::DEFAULT_GRACE_MINUTES.' : Minutes a session must have been ended}';

    protected $description = 'Remove operating sessions that ended with no contacts';

    public function handle(EmptySessionPruner $pruner): int
    {
        $removed = $pruner->pruneEndedBefore(now()->subMinutes((int) $this->option('minutes')));

        if ($removed > 0) {
            $this->info("Removed {$removed} empty ".str('session')->plural($removed).'.');
        }

        return self::SUCCESS;
    }
}
