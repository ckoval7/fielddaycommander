<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\Relay;
use App\Services\RelayEnrollmentService;
use Illuminate\Console\Command;

class RelayRevokeCommand extends Command
{
    protected $signature = 'relay:revoke {relay? : Relay ID (leave out to list relays)}';

    protected $description = 'Revoke a Field Day Commander Relay or list relays';

    public function handle(RelayEnrollmentService $enrollment): int
    {
        if ($this->argument('relay') === null) {
            $this->table(
                ['ID', 'Name', 'Event configuration', 'Version', 'Last seen', 'Revoked'],
                Relay::query()->orderBy('id')->get()->map(fn (Relay $relay): array => [
                    $relay->id,
                    $relay->name,
                    $relay->event_configuration_id,
                    $relay->relay_version,
                    $relay->last_seen_at?->toDateTimeString(),
                    $relay->revoked_at?->toDateTimeString(),
                ])->all(),
            );

            return self::SUCCESS;
        }

        $relay = Relay::active()->find($this->argument('relay'));

        if ($relay === null) {
            $this->error('No relay with that ID is available to revoke.');

            return self::FAILURE;
        }

        $enrollment->revoke($relay);
        $this->info("Relay {$relay->id} ({$relay->name}) revoked.");

        return self::SUCCESS;
    }
}
