<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\AgentEnrollmentService;
use Illuminate\Console\Command;

class AgentRevokeCommand extends Command
{
    protected $signature = 'agent:revoke {agent? : Agent ID (leave out to list agents)}';

    protected $description = 'Revoke a Field Day Commander Agent or list agents';

    public function handle(AgentEnrollmentService $enrollment): int
    {
        if ($this->argument('agent') === null) {
            $this->table(
                ['ID', 'Name', 'Event configuration', 'Version', 'Last seen', 'Revoked'],
                Agent::query()->orderBy('id')->get()->map(fn (Agent $agent): array => [
                    $agent->id,
                    $agent->name,
                    $agent->event_configuration_id,
                    $agent->agent_version,
                    $agent->last_seen_at?->toDateTimeString(),
                    $agent->revoked_at?->toDateTimeString(),
                ])->all(),
            );

            return self::SUCCESS;
        }

        $agent = Agent::active()->find($this->argument('agent'));

        if ($agent === null) {
            $this->error('No agent with that ID is available to revoke.');

            return self::FAILURE;
        }

        $enrollment->revoke($agent);
        $this->info("Agent {$agent->id} ({$agent->name}) revoked.");

        return self::SUCCESS;
    }
}
