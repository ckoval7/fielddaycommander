<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Setting;
use Illuminate\Console\Command;

class ActivateEventByDate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:activate-by-date';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically activate events based on date range';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $currentActiveEventId = Setting::get('active_event_id');
        $manualActivation = Setting::getBoolean('manual_activation', false);

        // Check if current active event is still within its date range
        if ($currentActiveEventId) {
            $activeEvent = Event::find($currentActiveEventId);

            // If active event exists but is outside its date range, deactivate it
            if ($activeEvent && ($activeEvent->start_time > appNow() || $activeEvent->end_time < appNow())) {
                Setting::set('active_event_id', null);
                Setting::set('manual_activation', false);
                $this->info("Auto-deactivated event (outside date range): {$activeEvent->name}");
                $manualActivation = false;
            }
        }

        // If manual activation is enabled, don't auto-activate a different event
        if ($manualActivation) {
            $this->info('Manual activation is enabled - skipping auto-activation');

            return self::SUCCESS;
        }

        // Find events that are currently in progress
        $event = Event::query()
            ->where('start_time', '<=', appNow())
            ->where('end_time', '>=', appNow())
            ->orderBy('created_at', 'asc')
            ->first();

        if ($event) {
            // Only activate if it's not already the active event
            if ($event->id != $currentActiveEventId) {
                Setting::set('active_event_id', $event->id);
                $this->info("Auto-activated event: {$event->name}");
            }

            return self::SUCCESS;
        }

        // No events match the current date range
        $this->info('No events match the current date range');

        // Clear active event if one was set
        if ($currentActiveEventId) {
            Setting::set('active_event_id', null);
            Setting::set('manual_activation', false);
            $this->info('Cleared active event');
        }

        return self::SUCCESS;
    }
}
