<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Services\DemoDatabasePool;
use Illuminate\Console\Command;

class DemoFillPool extends Command
{
    protected $signature = 'demo:fill-pool';

    protected $description = 'Keep a stock of migrated demo databases ready for new visitors';

    public function handle(DemoDatabasePool $pool): int
    {
        if (! config('demo.enabled')) {
            $this->info('Demo mode is disabled.');

            return self::SUCCESS;
        }

        $result = $pool->fill(max(0, (int) config('demo.pool_size', 5)));

        if ($result['forgotten'] > 0) {
            $this->warn("Forgot {$result['forgotten']} pooled database(s) that no longer exist.");
        }

        if ($result['created'] > 0 || $result['dropped'] > 0) {
            $this->info("Demo pool: created {$result['created']}, dropped {$result['dropped']}.");
        }

        return self::SUCCESS;
    }
}
