<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\EventConfiguration;
use App\Services\AgentEnrollmentService;
use App\Services\EventContextService;
use Illuminate\Console\Command;

class AgentIssueCodeCommand extends Command
{
    protected $signature = 'agent:issue-code
        {name : A name for this agent, such as the computer it runs on}
        {--event= : Event configuration ID (defaults to the active event)}';

    protected $description = 'Issue a one-time code that enrolls a Field Day Commander Agent';

    public function handle(AgentEnrollmentService $enrollment, EventContextService $events): int
    {
        $config = $this->option('event')
            ? EventConfiguration::find($this->option('event'))
            : $events->getEventConfiguration();

        if ($config === null) {
            $this->error('No event found. Use --event with an event configuration ID.');

            return self::FAILURE;
        }

        ['agent' => $agent, 'code' => $code] = $enrollment->issue($config, $this->argument('name'));

        $this->info("Agent {$agent->id} created. Enter this code in the agent within ".AgentEnrollmentService::CODE_LIFETIME_MINUTES.' minutes:');
        $this->line($code);

        return self::SUCCESS;
    }
}
