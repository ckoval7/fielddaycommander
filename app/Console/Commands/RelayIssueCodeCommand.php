<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\EventConfiguration;
use App\Services\EventContextService;
use App\Services\RelayEnrollmentService;
use Illuminate\Console\Command;

class RelayIssueCodeCommand extends Command
{
    protected $signature = 'relay:issue-code
        {name : A name for this relay, such as the computer it runs on}
        {--event= : Event configuration ID (defaults to the current event)}';

    protected $description = 'Create a one-time enrollment code for Field Day Commander Relay';

    public function handle(RelayEnrollmentService $enrollment, EventContextService $events): int
    {
        $config = $this->option('event')
            ? EventConfiguration::find($this->option('event'))
            : $events->getEventConfiguration();

        if ($config === null) {
            $this->error('No event found. Use --event with an event configuration ID.');

            return self::FAILURE;
        }

        ['relay' => $relay, 'code' => $code] = $enrollment->issue($config, $this->argument('name'));

        $this->info("Relay {$relay->id} created for event configuration {$config->id}. This code works once. Enter it in the relay within ".RelayEnrollmentService::CODE_LIFETIME_MINUTES.' minutes:');
        $this->line($code);

        return self::SUCCESS;
    }
}
